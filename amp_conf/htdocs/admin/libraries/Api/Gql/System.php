<?php

namespace FreePBX\Api\Gql;

use GraphQLRelay\Relay;
use GraphQL\Type\Definition\Type;
use FreePBX\modules\Api\Gql\Base;
use GraphQL\Type\Definition\ObjectType;

/**
 * System
 */
class System extends Base {
	public static function getScopes() {
		return [
				'read:system' => [
						'description' => _('Read system information'),
				],
				'write:system' => [
						'description' => _('Update System Informations'),
				]
		];
	}
	
	/**
	 * mutationCallback
	 *
	 * @return void
	 */
	public function mutationCallback() {
		if($this->checkReadScope('system')) {
			return function() {
				return [
				'addInitialSetup' => Relay::mutationWithClientMutationId([
					'name' => 'updateAdminAuth',
					'description' => _('This will Set the Administator Auth credentials'),
					'inputFields' => $this->getInputFields(),
					'outputFields' => $this->getOutputFields(),
					'mutateAndGetPayload' => function ($input) {
						return $this->addInitialSetup($input);
					}
				]),
				'updateSystemRPM' => Relay::mutationWithClientMutationId([
					'name' => 'updateSystemRPM',
					'description' => _('Update system RPM package'),
					'inputFields' => [],
					'outputFields' => $this->getOutputFields(),
					'mutateAndGetPayload' => function () {
						return $this->yumUpgrade();
					}
				])
				];
			};
		}
	}
	
	/**
	 * queryCallback
	 *
	 * @return void
	 */
	public function queryCallback() {
		if($this->checkReadScope("system")) {
			return function() {
				return [
					'system' => [
						'type' => $this->typeContainer->get('system')->getObject(),
						'description' => 'General System information',
						'resolve' => function($root, $args) {
							return []; //trick the resolver into not thinking this is null
						}
					],
					'fetchRPMUpgradeStatus' => [
						'type' => $this->typeContainer->get('system')->getObject(),
						'description' => _('Check the system yum-upgrade status'),
						'resolve' => function() {
							return $this->yumUpgradeStatus();
						}
					],
					'fetchAsteriskDetails' => [
						'type' => $this->typeContainer->get('system')->getObject(),
						'description' => _('Fetch asterisk details'),
						'resolve' => function() {
							return $this->asteriskDetails();
						}
					],
					'fetchDBStatus' => [
						'type' => $this->typeContainer->get('system')->getObject(),
						'description' => _('Fetch system status for DB'),
						'resolve' => function() {
							return $this->dbStatus();
						}
					],
					'fetchGUIMode' => [
						'type' => $this->typeContainer->get('system')->getObject(),
						'description' => _('Describes the GUI mode'),
						'resolve' => function() {
							return $this->guiMode();
						}
					],
					'fetchAutomaticUpdate' => [
						'type' => $this->typeContainer->get('system')->getObject(),
						'description' => _('Gets the status of automatic updates'),
						'resolve' => function() {
							return $this->autoUpdateSetting();
						}
					],
					'fetchSetupWizard' => [
						'type' => $this->typeContainer->get('updatestatus')->getConnectionType(),
						'description' => _('Gets the details of setup wizrd'),
						'resolve' => function() {
							return $this->setupWizardStatus();
						}
					]
				];
			};
		}
	}
	
	/**
	 * getInputFields
	 *
	 * @return void
	 */
	private function getInputFields() {
		return [
			'username' => [
				'type' => Type::nonNull(Type::string()),
				'description' => _('PBX GUI administrator username')
			],
			'password' => [
				'type' => Type::nonNull(Type::string()),
				'description' => _('PBX GUI administrator password')
			],
			'notificationEmail' => [
				'type' => Type::nonNull(Type::string()),
				'description' => _('Notification Email address')
			],
			'systemIdentifier' => [
				'type' => Type::nonNull(Type::string()),
				'description' => _('System Identity')
			],
			'autoModuleUpdate' => [
				'type' => Type::nonNull(Type::string()),
				'description' => _('Authomatic module updates(enabled,disabled,emailonly')
			],
			'autoModuleSecurityUpdate' => [
				'type' => Type::nonNull(Type::string()),
				'description' => _('Authomatic Module Security updates Email address(enabled,disabled)')
			],
			'securityEmailUnsignedModules' => [
				'type' => Type::nonNull(Type::string()),
				'description' => _('Send Security Emails for Unsigned Modules(enabled,disabled)')
			],
			'updateDay' => [
				'type' => Type::nonNull(Type::string()),
				'description' => _('Check for update on Every day (monday,tuesday,wednesday,thursday,friday,saterday,sunday)')
			],
			'updatePeriod' => [
				'type' => Type::nonNull(Type::string()),
				'description' => _('Update sytem time(0to4,4to8,8to12,12to16,16to20,20to0)')
			]
		];
	}
		
	/**
	 * initializeTypes
	 *
	 * @return void
	 */
	public function initializeTypes() {
		$user = $this->typeContainer->create('system', 'object');
		$user->setDescription('System Information');

		$user->addFieldCallback(function() {
			return [
				'id' => Relay::globalIdField('system', function($row) {
					if(isset($row)){
						return $row['id'];
					}else{
						return null;
					}
				}),
				'version' => [
					'type' => Type::string(),
					'description' => 'Version of the PBX',
					'resolve' => function ($root, $args) {
						return getVersion();
					}
				],
				'engine' => [
					'type' => Type::string(),
					'description' => 'Version of Asterisk',
					'resolve' => function ($root, $args) {
						return engine_getinfo()['version'];
					}
				],
				'needReload' => [
					'type' => Type::boolean(),
					'description' => 'Does the system need to be reloaded?',
					'resolve' => function ($root, $args) {
						return check_reload_needed();
					}
				],
				'message' =>[
					'type' => Type::string(),
					'description' => _('Message for the request')
				],
				'status' =>[
					'type' => Type::boolean(),
					'description' => _('Status for the request')
				],
				'asteriskStatus' =>[
					'type' => Type::string(),
					'description' => _('Message for the request')
				],
				'asteriskVersion' =>[
					'type' => Type::string(),
					'description' => _('ASterisk Version')
				],
				'amiStatus' =>[
					'type' => Type::string(),
					'description' => _('AMI/ARM status')
				],
				'dbStatus' =>[
					'type' => Type::string(),
					'description' => _('Status of the database')
				],
				'guiMode' =>[
					'type' => Type::string(),
					'description' => _('GUI mode')
				],
				'systemUpdates' =>[
					'type' => Type::string(),
					'description' => _('Status of syatem update')
				],
				'moduleUpdates' =>[
					'type' => Type::string(),
					'description' => _('Status of module update')
				],
				'moduleSecurityUpdates' =>[
					'type' => Type::string(),
					'description' => _('Status of module security update')
				],
			];
		});

		$system = $this->typeContainer->create('updatestatus');
		$system->addInterfaceCallback(function() {
			return [$this->getNodeDefinition()['nodeInterface']];
		});

	$system->addFieldCallBack(function(){
		return[
			'id' => Relay::globalIdField('updatestatus', function($row) {
				if(isset($row)){
					return $row['id'];
				}else{
					return null;
				}
			}),
			'modules' =>[
			'type' => Type::string(),
			'description' => _('Status of automatic updates'),
			'resolve' => function($row) {
				if(isset($row)){
					return $row['val'];
				}else{
					return null;
				}
			}
			],];
		});

	$system->setConnectionFields(function() {
		return [
			'autoupdates' => [
				'type' =>  Type::listOf($this->typeContainer->get('updatestatus')->getObject()),
				'description' => _('status of automatic updates'),
				'resolve' => function($root, $args) {
					$data = array_map(function($row){
						return $row;
					},$root['response']);
						return $data;
				}
			],
			'message' =>[
				'type' => Type::string(),
				'description' => _('Message for the request')
			],
			'status' =>[
				'type' => Type::boolean(),
				'description' => _('Status for the request')
			]];
		});
	}
	
	/**
	 * addInitialSetup
	 *
	 * @param  mixed $settings
	 * @return void
	 */
	private function addInitialSetup($settings) {
		$db = $this->freepbx->Database();
		$username = htmlentities(strip_tags($settings['username']));

		//validate if user already exists
		$sql = $db->prepare("SELECT * FROM ampusers WHERE username LIKE ?");
		$sql->execute(array("%".$username."%"));
		$rows = $sql->fetchAll(\PDO::FETCH_ASSOC);
		if (count($rows) > 0) {
			$message = _("Admin user already exists, updating other parameters");
		}else{
			$sth = $db->prepare("INSERT INTO `ampusers` (`username`, `password_sha1`, `sections`) VALUES ( ?, ?, '*')");
			$sth->execute(array($username, sha1($settings['password'])));
			$message = _("Initial Setup is completed");
		}

		$settings = $this->resolveNames($settings);
		$um = new \FreePBX\Builtin\UpdateManager();
		$um->updateUpdateSettings($settings);
		$um->setNotificationEmail($settings['notification_emails']);
		// need to make in OOBE as framework completed
		$this->completeOOBE('framework');
		return ['message' => $message,'status' => true];
	}
	
	/**
	 * completeOOBE
	 *
	 * @param  mixed $mod
	 * @return void
	 */
	private function completeOOBE($mod = false) {
		if (!$mod) {
			throw new \Exception("No module given to mark as complete");
		}
		$complete = $this->freepbx->OOBE->getConfig("completed");
		if (!is_array($complete)) {
			$complete = array($mod => $mod);
		} else {
			$complete[$mod] = $mod;
		}
		$this->freepbx->OOBE->setConfig("completed", $complete);
	}
	
	/**
	 * getOutputFields
	 *
	 * @return void
	 */
	private function getOutputFields(){
		return [
			'message' =>[
				'type' => Type::string(),
				'description' => _('Message for the request')
			],
			'status' =>[
				'type' => Type::boolean(),
				'description' => _('Status for the request')
			],
			'transaction_id' => [
				'type' => Type::string(),
				'description' => _('Transaction Id for status check')
			]
		];
	}
	
	/**
	 * yumUpgrade
	 *
	 * @return void
	 */
	private function yumUpgrade(){
		try{
			$res = $this->freepbx->Framework->getSystemObj()->startYumUpdate();
			if($res){
				return ['message' => _('Yum Upgrade has been initiated. Please check the status using yumUpgradeStatus api'),'status' => true];
			}else{
				return ['message' => _('Yum Upgrade is already running'),'status' => true];
			}
		}catch(Exception $ex){
			return ['message' => _($ex->message) , 'status' => false];
		}
	}
	
	/**
	 * yumUpgradeStatus
	 *
	 * @return void
	 */
	private function yumUpgradeStatus(){
		$res = $this->freepbx->Framework->getSystemObj()->getYumUpdateStatus();
		
		if($res['status'] == "complete"){
			return ['message' => _('Yum upgrade is completed'), 'status' => true];
		}elseif($res['status'] == "inprogress"){
			return ['message' => _('Yum upgrade is in progress'), 'status' => true];
		}else{
			return ['message' => _('Sorry, yum upgrade has failed'), 'status' => false];
		}
	}
	
	/**
	 * resolvenames
	 *
	 * @return void
	 */
	private function resolvenames($settings){
		$settings['notification_emails'] = $settings['notificationEmail'];
		$settings['system_ident'] = $settings['systemIdentifier'];
		$settings['auto_module_updates'] = $settings['autoModuleUpdate'];
		$settings['auto_module_security_updates'] = $settings['autoModuleSecurityUpdate'];
		$settings['unsigned_module_emails'] = $settings['securityEmailUnsignedModules'];
		$settings['update_every'] = $settings['updateDay'];
		$settings['update_period'] = $settings['updatePeriod'];
		return $settings;
	}
	
	/**
	 * asteriskDetails
	 *
	 * @return void
	 */
	private function asteriskDetails(){
		$asterisk_version = engine_getinfo();
		if($asterisk_version){
			$asterisk_version = $asterisk_version['version'];
		}else{
			$asterisk_version = "";
		}
		if(file_exists('/var/run/asterisk/asterisk.ctl')){
			$asterisk_status = _("Running");
		}else{
			$asterisk_status = _("Not running");
		}
		$ami_ari = $this->freepbx->astman->connected();
		if($ami_ari){
			$ami_ari = _("Connected");
		}else{
			$ami_ari = _("Not Connected");
		}
		return ['message' => _('Asterisk Details'), 'status' => true , 'asteriskStatus' => $asterisk_status, 'asteriskVersion' => $asterisk_version ,'amiStatus' => $ami_ari];
	}
	
	/**
	 * dbStatus
	 *
	 * @return void
	 */
	private function dbStatus(){
		if(DB_OK){
			$db_status = _('Connected');
		}else{
			$db_status = _('Not Connected');
		}
		return ['message' => _('Database Status'), 'status' => true , 'dbStatus' => $db_status];
	}
	
	/**
	 * guiMode
	 *
	 * @return void
	 */
	private function guiMode(){
		$res = $this->freepbx->Config()->get('FPBXOPMODE');
		if($res){
			return ['message' => _('GUI Mode'), 'status' => true , 'guiMode' => $res];
		}else{
		   return ['message' => _('Sorry, GUI mode details not avaliable'), 'status' => false];
		}
	}
	
	/**
	 * setupWizardStatus
	 *
	 * @return void
	 */
	private function setupWizardStatus(){
		$sql = $this->freepbx->database->prepare("SELECT val FROM `kvstore_OOBE` where `key` like ?");
		$sql->execute(array('completed'));
		$res = $sql->fetchAll(\PDO::FETCH_ASSOC);
		if(!empty($res)){
			return ['message' => _('List up moduels setup wizard is run for'), 'status' => true , 'response' => $res];
		}else{
			return ['message' => _('Setup wizard is not run for any module'), 'status' => false];
		}	
	}
	
	/**
	 * autoUpdateSetting
	 *
	 * @return void
	 */
	private function autoUpdateSetting(){
		$um = new \FreePBX\Builtin\UpdateManager();
		$row = $um->getCurrentUpdateSettings();
		if(!empty($row)){
			return ['message' => _('List of automatic update status'), 'status' => true , 'systemUpdates' =>  $row['auto_system_updates'], 'moduleUpdates' =>  $row['auto_module_updates'] , 'moduleSecurityUpdates' => $row['auto_module_security_updates']];
		}
		return ['message' => _('Sorry, Could not find any update status'), 'status' => false];
	}
}