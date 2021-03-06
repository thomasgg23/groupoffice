<?php
namespace go\modules\community\addressbook\model;
						
use go\core\orm\Property;
						
/**
 * ContactStar model
 *
 * @copyright (c) 2018, Intermesh BV http://www.intermesh.nl
 * @author Merijn Schering <mschering@intermesh.nl>
 * @license http://www.gnu.org/licenses/agpl-3.0.html AGPLv3
 */

class ContactStar extends \go\core\jmap\Entity {
	
	/**
	 * 
	 * @var int
	 */							
	public $contactId;

	/**
	 * 
	 * @var int
	 */							
	public $userId;

	/**
	 * 
	 * @var bool
	 */							
	public $starred = false;

	protected static function defineMapping() {
		return parent::defineMapping()
						->addTable("addressbook_contact_star");
	}

}