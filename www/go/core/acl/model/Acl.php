<?php
namespace go\core\acl\model;

use go\core\App;
use go\modules\core\groups\model\Group;
use go\modules\core\users\model\User;
use go\core\db\Criteria;
use go\core\db\Query;
use go\core\orm\Mapping;
use go\core\orm\Property;
use go\core\util\DateTime;

/**
 * The Acl class
 * 
 * Is an Access Control List to restrict access to data.
 */
class Acl extends \go\core\jmap\Entity {
	
	const LEVEL_READ = 10;
	const LEVEL_CREATE = 20;
	const LEVEL_WRITE = 30;
	const LEVEL_DELETE = 40;
	const LEVEL_MANAGE = 50;
	
	
	public $id;
	
	/**
	 * The table.field this aclId is used in
	 * 
	 * @var string
	 */
	public $usedIn;
	
	/**
	 * The user that owns the ACL
	 * @var int
	 */
	public $ownedBy;
	
	/**
	 * Modification time
	 * 
	 * @var DateTime
	 */
	public $modifiedAt;
	
	/**
	 * The list of groups that have access
	 * 
	 * @var AclGroup[] 
	 */
	public $groups = [];
	
	protected static function defineMapping() {
		return parent::defineMapping()
						->addTable('core_acl')
						->addRelation('groups', AclGroup::class, ['id' => 'aclId'], true);
	}
	
	
	protected function internalSave() {
		
		if($this->isNew()) {
			if(empty($this->groups)) {			
			
				$this->groups[] = (new AclGroup())
							->setValues([
									'groupId' => Group::ID_ADMINS, 
									'level' => self::LEVEL_MANAGE
											]);

				if($this->ownedBy != User::ID_SUPER_ADMIN) {

					$groupId = Group::find()
									->where(['isUserGroupFor' => $this->ownedBy])
									->selectSingleValue('id')
									->single();

					$this->groups[] = (new AclGroup())
									->setValues([
											'groupId' => $groupId, 
											'level' => self::LEVEL_MANAGE
													]);
				}
			}
		} else {
			
			//add admins if removed.
			if($this->isModified(['groups']) && !$this->hasAdmins()) {
				$this->groups[] = (new AclGroup())
							->setValues([
									'groupId' => Group::ID_ADMINS, 
									'level' => self::LEVEL_MANAGE
											]);
			}
		}
		
		if(!parent::internalSave()) {
			return false;
		}
		
		return $this->logChanges();		
	}
	
	private function hasAdmins() {
		foreach($this->groups as $group) {
			if($group->groupId == Group::ID_ADMINS) {
				return true;
			}
		}

		return false;
	}
	
	private function logChanges() {
		
		if(!\go\core\jmap\Entity::$trackChanges) {
			return true;
		}
		
		$modified = $this->getModified(['groups']);
		
		if(!isset($modified['groups'])) {
			return true;
		}
		
		$currentGroupIds = array_column($modified['groups'][0], 'groupId');
		$oldGroupIds = array_column($modified['groups'][1], 'groupId');
		
		$addedGroupIds = array_diff($currentGroupIds, $oldGroupIds);
		$removedGroupIds = array_diff($oldGroupIds, $currentGroupIds);
	
		if(empty($addedGroupIds) && empty($removedGroupIds)) {
			return true;
		}
		
		$modSeq = Acl::getType()->nextModSeq();
		
		foreach($addedGroupIds as $groupId) {
			$success = App::get()->getDbConnection()
							->insert('core_acl_group_changes', 
											[
													'aclId' => $this->id, 
													'groupId' => $groupId, 
													'grantModSeq' => $modSeq,
													'revokeModSeq' => null
											]
											)->execute();
			if(!$success) {
				return false;
			}
		}
		
		foreach ($removedGroupIds as $groupId) {
			$success = App::get()->getDbConnection()
						->update('core_acl_group_changes', 
										[												
											'revokeModSeq' => $modSeq											
										],
										[
											'aclId' => $this->id, 
											'groupId' => $groupId,
											'revokeModSeq' => null
										]
										)->execute();
			if(!$success) {
				return false;
			}
		}
		
		return true;		
	}
	
	/**
	 * Adds a where exists condition so only items that are readable to the current user are returned.
	 * 
	 * @param Query $query
	 * @param string $column eg. t.aclId
	 * @param int $level The required permission level
	 */
	public static function applyToQuery(Query $query, $column, $level = self::LEVEL_READ) {
		
		$subQuery = (new Query)
						->select('aclId')
						->from('core_acl_group', 'acl_g')
						->where('acl_g.aclId = '.$column)
						->join('core_user_group', 'acl_u' , 'acl_u.groupId = acl_g.groupId')
						->andWhere([
								'acl_u.userId' => App::get()->getAuthState()->getUserId()						
										])
						->andWhere('acl_g.level', '>=', $level);
		
		$query->whereExists(
						$subQuery
						);
	}
	
	private static $permissionLevelCache = [];
	
	/**
	 * Get the maximum permission level a user has for an ACL
	 * 
	 * @param int $aclId
	 * @param int $userId
	 * @return int See the self::LEVEL_* constants
	 */
	public static function getUserPermissionLevel($aclId, $userId) {
		
		$cacheKey = $aclId . "-" . $userId;
		if(!isset(self::$permissionLevelCache[$cacheKey])) {
			$query = (new Query())
							->selectSingleValue('MAX(level)')
							->from('core_acl_group', 'g')
							->join('core_user_group', 'u', 'g.groupId = u.groupId')
							->where(['g.aclId' => $aclId, 'u.userId' => $userId])
							->groupBy(['g.aclId']);

			self::$permissionLevelCache[$cacheKey] = (int) $query->execute()->fetch();
		}
		
		App::get()->debug("Permission level ($cacheKey) = " . self::$permissionLevelCache[$cacheKey]);
		
		return self::$permissionLevelCache[$cacheKey];
	}
	
	/**
	 * Get all ACL id's that have been granted since a given state
	 * 
	 * @param int $userId 
	 * @param int $sinceState	 
	 * @return Query
	 */
	public static function findGrantedSince($userId, $sinceState, Query $acls = null) {
		
		//select ag.aclId from core_acl_group ag 
		//inner join core_user_group ug on ag.groupId = ug.groupId
		//where ug.userId = 4
		//
		//and ag.aclId not in (
		//	select agc.aclId from core_acl_group_changes agc 
		//	inner join core_user_group ugc on agc.groupId = ugc.groupId
		//	where ugc.userId = 4 and agc.grantModSeq <= 3  AND (agc.revokeModSeq IS null or agc.revokeModSeq > 3)
		//
		//)

		
		return self::areGranted($userId, $acls)
						->andWhere('ag.aclId', 'NOT IN', self::wereGranted($userId, $sinceState, $acls));		
	}
	
	/**
	 * Get all ACL id's that have been revoked since a given state
	 * 
	 * @param int $userId
	 * @param int $sinceState
	 * @return Query
	 */
	public static function findRevokedSince($userId, $sinceState, Query $acls = null) {
		
		//select agc.aclId from core_acl_group_changes agc 
		//inner join core_user_group ugc on agc.groupId = ugc.groupId
		//where ugc.userId = 4 and agc.grantModSeq <= 3  AND (agc.revokeModSeq IS null or agc.revokeModSeq > 3)
		//
		//and agc.aclId not in (
		//	select ag.aclId from core_acl_group ag 
		//	inner join core_user_group ug on ag.groupId = ug.groupId
		//	where ug.userId = 4
		//)
		
		return self::wereGranted($userId, $sinceState, $acls)
						->andWhere('agc.aclId', 'NOT IN', self::areGranted($userId, $acls));		
	}
	

	
	/**
	 * 
	 * @param int $userId
	 * @return Query
	 */
	public static function areGranted($userId, Query $acls = null) {
		$query = (new Query())
						->selectSingleValue('ag.aclId')
						->from('core_acl_group', 'ag')
						->join('core_user_group', 'ug', 'ag.groupId = ug.groupId')
						->where('ug.userId', '=', $userId);
		
		if(isset($acls)) {
			$query->andWhere('ag.aclId', 'IN', $acls);
		}
		
		return $query;
	}
	
	public static function wereGranted($userId, $sinceState, Query $acls = null) {
		$query = (new Query())
						->selectSingleValue('agc.aclId')
						->from('core_acl_group_changes', 'agc')
						->join('core_user_group', 'ugc', 'agc.groupId = ugc.groupId')
						->where('ugc.userId', '=', $userId)
						->andWhere('agc.grantModSeq', '<=', $sinceState)
						->andWhere(
										(new Criteria())
										->where('agc.revokeModSeq', 'IS', NULL)
										->orWhere('agc.revokeModSeq', '>', $sinceState)
										);
		
		if(isset($acls)) {
			$query->andWhere('agc.aclId', 'IN', $acls);
		}
		
		return $query;
	}	
}
