<?php
namespace go\modules\community\addressbook\model;

use go\core\acl\model\AclItemEntity;
use go\core\orm\CustomFieldsTrait;
						
/**
 * Contact model
 *
 * @copyright (c) 2018, Intermesh BV http://www.intermesh.nl
 * @author Merijn Schering <mschering@intermesh.nl>
 * @license http://www.gnu.org/licenses/agpl-3.0.html AGPLv3
 */

class Contact extends AclItemEntity {
	
	use CustomFieldsTrait;
	
	/**
	 * 
	 * @var int
	 */							
	public $id;

	/**
	 * 
	 * @var int
	 */							
	public $addressBookId;

	/**
	 * 
	 * @var int
	 */							
	public $createdBy;

	/**
	 * 
	 * @var \IFW\Util\DateTime
	 */							
	public $createdAt;

	/**
	 * 
	 * @var \IFW\Util\DateTime
	 */							
	public $modifiedAt;

	/**
	 * Prefixes like 'Sir'
	 * @var string
	 */							
	public $prefixes = '';

	/**
	 * 
	 * @var string
	 */							
	public $firstName = '';

	/**
	 * 
	 * @var string
	 */							
	public $middleName = '';

	/**
	 * 
	 * @var string
	 */							
	public $lastName = '';

	/**
	 * Suffixes like 'Msc.'
	 * @var string
	 */							
	public $suffixes = '';

	/**
	 * M for Male, F for Female or null for unknown
	 * @var string
	 */							
	public $gender;

	/**
	 * 
	 * @var string
	 */							
	public $notes;

	/**
	 * 
	 * @var bool
	 */							
	public $isOrganization = false;

	/**
	 * name field for companies and contacts. It should be the display name of first, middle and last name
	 * @var string
	 */							
	public $name;

	/**
	 * 
	 * @var string
	 */							
	public $IBAN = '';

	/**
	 * Company trade registration number
	 * @var string
	 */							
	public $registrationNumber = '';

	/**
	 * 
	 * @var string
	 */							
	public $vatNo;

	/**
	 * 
	 * @var string
	 */							
	public $debtorNumber;

	/**
	 * 
	 * @var string
	 */							
	public $photoBlobId;

	/**
	 * 
	 * @var string
	 */							
	public $language;
	
	/**
	 *
	 * @var EmailAddress[]
	 */
	public $emailAddresses = [];
	
	/**
	 *
	 * @var PhoneNumber[]
	 */
	public $phoneNumbers = [];
	
	/**
	 *
	 * @var Date[];
	 */
	public $dates = [];
	
	/**
	 *
	 * @var Url[]
	 */
	public $urls = [];
	
	/**
	 *
	 * @var ContactOrganization[]
	 */
	public $organizations = [];
	
	
	/**
	 *
	 * @var Address[]
	 */
	public $addresses = [];	
	
	/**
	 *
	 * @var ContactGroup[] 
	 */
	public $groups = [];

	protected static function aclEntityClass(): string {
		return AddressBook::class;
	}

	protected static function aclEntityKeys(): array {
		return ['addressBookId' => 'id'];
	}
	
	protected static function defineMapping() {
		return parent::defineMapping()
						->addTable("addressbook_contact", 'c')
						->addRelation('dates', Date::class, ['id' => 'contactId'])
						->addRelation('phoneNumbers', PhoneNumber::class, ['id' => 'contactId'])
						->addRelation('emailAddresses', EmailAddress::class, ['id' => 'contactId'])
						->addRelation('addresses', Address::class, ['id' => 'contactId'])
						->addRelation('organizations', ContactOrganization::class, ['id' => 'contactId'])
						->addRelation('urls', Url::class, ['id' => 'contactId'])
						->addRelation('groups', ContactGroup::class, ['id' => 'contactId']);
	}
	
	public static function filter(\go\core\db\Query $query, array $filter) {
		if (isset($filter['addressBookId'])) {
			$query->andWhere('addressBookId', '=', $filter['addressBookId']);
		}
		
		if (isset($filter['groupId'])) {
			$query->join('addressbook_contact_group', 'g', 'g.contactId = c.id')
							->andWhere('g.groupId', '=', $filter['groupId']);
		}
		
		return parent::filter($query, $filter);
	}

}