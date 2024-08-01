<?
require_once('ProviderApiInterface.class.php');
/**
Handles all API interactions with the Uncomprimized API.
Usually not instantiated directly.  Called by ProviderApi.class.php. Check cron daemons for exceptions with direct instantiating.
@implements ProviderApiInterface, as must any Provider API class that plans to use this as the gateway
@copyright: 2024 defend-id
@author: Scott Blanchard
*/
final class UncompromizedApi implements ProviderApiInterface{
	/**
	Stores an instance of a Core class object passed to $this->__construct() by reference
	*/
	private $core=NULL;
	/**
	Filename of the config file for live server connections.
	*/
	private $connectFile='uncompromized-api.ini';
	/**
	Filename of the config file for dev or debug server connections.
	*/
	private $debugConnectFile='uncompromized-api-debug.ini';
	/**
	Array to hold the connection settings parsed from the config file for the API.
	*/
	private $connectionString=array();
	/**
	Array to hold the collection of active products and their API codes found in mis_settings_tbl
	*/
	private $products=array();
	/**
	Holds a collection of members being processed.  Mainly used to avoid excessive argument passing.
	*/
	private $members=array();
	/**
	Holds a collection of members being processed.  Mainly used to avoid excessive argument passing.
	*/
	private $groups=array();
	/**
	Relative path to the user enrollment XML template
	*/
	private const NEW_USER_ENROLLMENT_TEMPLATE='/xml/create-new-user-enrollment.xml';
	/**
	Array of placeholders for the start enrollment template.  Template location stored in const self::NEW_USER_ENROLLMENT_TEMPLATE
	*/
	private const START_OPTIN_ENROLLMENT_PLACEHOLDERS=array(
		'[[username]]',
		'[[password]]',
		'[[partner_id]]',
		'[[member_id]]',
		'[[user_email_address]]',
		'[[user_password]]',
		'[[ssn]]',
		'[[dob]]',
		'[[first_name]]',
		'[[middle_name]]',
		'[[last_name]]',
		'[[suffix]]',
		'[[address]',
		'[[address_2]]',
		'[[city]]',
		'[[state]]',
		'[[zip]]',
		'[[country_code]]',
		'[[language_code]]',
		'[[phone]]',
		'[[phone_type]]',
		'[[sms_number]]',
		'[[bundle_id]]',
		'[[generate_token]]',
		'[[error_msg]]'
	);
	/**
	Stores an instance of a passed by reference Core object to an internal member.
	Verifies connection string to API can be found and read.
	Initializes Products object
	@param Core $core = passed by reference site Core object
	@param bool $testRun = flag for specific developer test runs
	@return void
	*/
	function __construct(Core &$core,bool $testRun=false){
		$this->core=$core;
		if(!$this->_getConnectionSettings()){
			die('FATAL ERROR: SITE CORE FAILED TO INITIALIZE.  CHECK ERROR LOGS FOR MORE INFORMATION');
		}
		$this->products=$this->getProducts();
	}
	/**
	Public gateway to various private data retrieval functions.
	Required function for this class to work with ProviderApiInterface.
	@param string $what = type of data to retrieve
	@param array $opts = optional array of args and settings for the data retrieval
	@return array, typically with keys of 'succcess'(int), 'response'(string), 'data'(array)
	*/
	public function get(string $what,array $opts=array()):array{
		$return=array('success'=>0,'response'=>'Invalid argument passed to '.__METHOD__);
		switch($what){
			case 'products':
				$return=$this->getProducts($opts);
				break;
			case 'product':
				$return=$this->getProduct($opts);
				break;
			case 'api-args':
				$return=$this->getAPIArgs($opts);
				break;
			default:
				$this->core->recordError('Invalid type passed to '.__METHOD__);
		}
		return $return;
	}
	/**
	Public gateway to set various private class properties.
	Required function for this class to work with ProviderApiInterface.
	***Not currently implemented.  Just returns false.***
	@param string $what = name of class property to update
	@param array $opts = optional array of args and settings class property update
	@return bool
	*/
	public function set(string $what,array $opts=array()):bool{
		$e=false;
		return $e;
	}
	/**
	Public gateway to run various private API call and other data processing functions.
	Required function for this class to work with ProviderApiInterface.
	@param string $what = type of API function to perform
	@param array $opts = optional array of args and settings for processing function
	@return array, typically with keys of 'succcess'(int), 'response'(string), 'report'(array)
	*/
	public function run(string $what,array $opts=array()):array{
		$e=array('success'=>0,'response'=>'');
		switch($what){
			case 'export-orders':
				$e=$this->exportOrders();
				break;
			case 'process-removed-members':
				$e=$this->processRemovedMembers();
				break;
			case 'process-optin-members':
				$e=$this->enrollOptinConsumer($opts);
				break;
			default:
				$e['response']='Invalid argument passed to '.__METHOD__;
		}
		return $e;
	}
	/**
	Public gateway to private API start enrollment functions.
	Required function for this class to work with ProviderApiInterface.
	@param string $type = type of API enrollment function to perform. Current valid options are 'consumer', 'business'.
	@param array $opts = array of args and settings for processing function
	@return array, with keys of 'succcess'(int), 'response'(string), 'api_response'(string)
	*/
	public function startEnrollment(string $type, array $opts):array{
		$e=array('success'=>0,'response'=>'','api_response'=>'');
		switch($type){
			case 'optin':
				$e=$this->enrollOptinConsumer($opts);
				break;
			case 'consumer':
				$e=$this->enrollConsumer($opts);
				break;
			case 'business':
				$e=$this->enrollBusinessAdministrator($opts);
				break;
			default:
				$e['response']='Invalid type passed to '.__METHOD__;
		}
		return $e;
	}
	/**
	Public gateway to private API cancel enrollment functions.
	Required function for this class to work with ProviderApiInterface.
	@param string $type = type of API cancellation function to perform. Current valid options are 'consumer', 'business'.
	@param array $opts = array of args and settings for processing function
	@return array, with keys of 'succcess'(int), 'response'(string), 'api_response'(string)
	*/
	public function cancelEnrollment(string $type, array $opts):array{
		$e=array('success'=>0,'response'=>'','api_response'=>'');
		switch($type){
			case 'consumer':
				$e=$this->cancelConsumer($opts);
				break;
			case 'business':
				$e=$this->cancelBusiness($opts);
				break;
			default:
				$e['response']='Invalid type passed to '.__METHOD__;
		}
		return $e;
	}
	private function enrollOptinConsumers(){

	}	
	private function enrollOptinConsumer(array $member):array{
		$e=array('success'=>0,'response'=>'','api_response'=>'');
		$args=$this->getAPIArgs($member);
		if($args['success']==1){
			$template=file_get_contents( __DIR__.self::NEW_USER_ENROLLMENT_TEMPLATE);
			$opts=array(
				$this->connectionString['username'],
				$this->connectionString['password'],
				$this->connectionString['partner_id'],
				$this->connectionString['member_name'],
				(isset($member['user_email_address']) && strlen(trim($member['user_email_address']))?$member['user_email_address']:''),
				(isset($member['user_password']) && strlen(trim($member['user_password']))?$member['user_password']:''),
				(isset($member['ssn']) && strlen(trim($member['ssn']))?$member['ssn']:''),
				(isset($member['dob']) && strlen(trim($member['dob']))?$member['dob']:''),
				(isset($member['first_name']) && strlen(trim($member['first_name']))?$member['first_name']:''),
				(isset($member['middle_name']) && strlen(trim($member['middle_name']))?$member['middle_name']:''),
				(isset($member['last_name']) && strlen(trim($member['last_name']))?$member['last_name']:''),
				(isset($member['suffix']) && strlen(trim($member['suffix']))?$member['suffix']:''),
				(isset($member['address']) && strlen(trim($member['address']))?$member['address']:''),
				(isset($member['address_2']) && strlen(trim($member['address_2']))?$member['address_2']:''),
				(isset($member['city']) && strlen(trim($member['city']))?$member['city']:''),
				(isset($member['state']) && strlen(trim($member['state']))?$member['state']:''),
				(isset($member['zip']) && strlen(trim($member['zip']))?$member['zip']:''),
				(isset($member['country_code']) && strlen(trim($member['country_code']))?$member['country_code']:''),
				(isset($member['language_code']) && strlen(trim($member['language_code']))?$member['language_code']:''),
				(isset($member['phone']) && strlen(trim($member['phone']))?$member['phone']:''),
				(isset($member['phone_type']) && strlen(trim($member['phone_type']))?$member['phone_type']:''),
				(isset($member['sms_number']) && strlen(trim($member['sms_number']))?$member['sms_number']:''),
				$args['bundle_id'].'|'.$args['seq_id'],
				(isset($member['generate_token']) && strlen(trim($member['generate_token']))?$member['generate_token']:''),
				(isset($member['error_msg']) && strlen(trim($member['error_msg']))?$member['error_msg']:'')
			);
			$xml=str_replace(self::START_OPTIN_ENROLLMENT_PLACEHOLDERS,$opts,$template);
			$res=$this->_callAPI($xml);
			$e['api_response']=$res['api_response'];
			$xml=str_ireplace(['SOAP-ENV:','SOAP:'],'',$res['api_response']);
			$xml=simplexml_load_string($xml);
			if($xml!==false){
				$res=trim($xml->Body->CreateNewUserEnrollmentResponse->CreateNewUserEnrollmentResult);
				if(strlen($res)>1){
					$e['success']=1;
				}
				else{
					$i=1;
					foreach($xml->Body->CreateNewUserEnrollmentResponse->ErrMsg as $msg){
						$e['response'].=(($i++).'. '.$msg.PHP_EOL.'<br />');
					}
				}
			}
			else{
				$errors=libxml_get_errors();
				$e['response']='Error parsing XML response from API.  Errors given:';
				for($i=0; $i<count($errors); $i++){
					$e['response'].=(($i+1)+'. '.$errors[$i].PHP_EOL.'<br />');
				}
			}
		}
		else{
			$e['response']=$args['response'];
		}
		return $e;
	}
	/**
	Process members that have removed from a census, but not yet cancelled at the provider.
	Callable through the public run function.
	@return array, with keys of 'succcess'(int), 'response'(string), 'execution_time'(int), 'success_count'(int),'failure_count'(int), 'report'(array)
	*/
	private function processRemovedMembers():array{
		$e=array('success'=>0,'response'=>'','execution_time'=>0,'success_count'=>0,'failure_count'=>0,'report'=>array('success'=>array(),'failure'=>array()));
		$start=microtime(true);
		$misFailures=0;
		$misSuccesses=0;
		$members=$this->getDeletedMembers();
		if($members['success']==1){
			foreach($members['data'] as $orderId=>$orderMembers){
				for($i=0; $i<count($orderMembers); $i++){
					if((microtime(true) - $start) < (59*60)){
						$args=$this->getAPIArgs($orderMembers[$i]);
						$opts=array(
							'cust_id'=>$args['customer_id'],
							'member_guid'=>$orderMembers[$i]['member_guid']
						);
						$result=$this->cancelConsumer($opts);
						if($result['success']==1){
							$e['report']['success'][]=array('success'=>1,'response'=>'','result'=>$result);
							$misSuccesses++;
							$this->updateMember('removed',$orderMembers[$i]['id'],$result['api_response']);
							unset($members['data'][$orderId][$i]);
						}
						else{
							$e['report']['failure'][]=array('success'=>0,'data'=>$opts,'result'=>$result);
							$misFailures++;
						}
					}
					if(count($members['data'][$orderId])===0){
						require_once('Order.class.php');
						$orderCore=new Order($this->core,$this->core->user);
						$this->sendReceipt($orderCore->get('order',array('id'=>$orderId)),'remove-members');
					}
				}
			}
			$e['success_count']=$misSuccesses;
			$e['failure_count']=$misFailures;
			$e['execution_time']=(microtime(true) - $start);
			if($misFailures==0){
				$e['success']=1;
			}
		}
		else if($members['success']==2){
			$e['response']='MIS API ran with no errors, but found no individual members found that still require removal processing.';
			$e['success']=2;
		}
		else{
			$e['response']='An internal error occurred looking up individual members that still require removal processing.';
		}
		return $e;
	}
	/**
	Process new orders and members that have been added to a census, but not yet enrolled at the provider
	Callable through the public run function.
	@return array, with keys of 'succcess'(int), 'response'(string), ,'report'(array)
	*/
	private function exportOrders():array{
		$e=array(
			'success'=>0,
			'response'=>'',
			'report'=>array(
				'individual'=>array('success'=>0,'results'=>array('success'=>array(),'failure'=>array())),
				'business'=>array('success'=>0,'results'=>array('success'=>array(),'failure'=>array())),
				'complete'=>array()
			)
		);
		$start=microtime(true);
		$misFailures=0;
		$misSuccesses=0;
		$this->members=$this->getUnprocessed();
		$string=json_encode($this->members['data']);
		$now=time();
		
		if($this->members['success']==1){
			foreach($this->members['data'] as $orderId=>$members){
				for($i=0; $i<count($members); $i++){
					if((microtime(true) - $start) < (59*60)){
						$args=$this->getAPIArgs($members[$i]);
						$opts=array(
							'customer_id'=>$args['customer_id'],
							'bundle_id'=>$args['bundle_id'],
							'unique_id'=>$members[$i]['member_guid'],
							'first_name'=>$members[$i]['first_name'],
							'last_name'=>$members[$i]['last_name'],
							'email'=>$members[$i]['email'],
							'phone'=>(isset($members[$i]['phone']) && strlen(trim($members[$i]['phone']))?$members[$i]['phone']:''),
							'address'=>(isset($members[$i]['address']) && strlen(trim($members[$i]['address']))?$members[$i]['address']:''),
							'city'=>(isset($members[$i]['city']) && strlen(trim($members[$i]['city']))?$members[$i]['city']:''),
							'state'=>(isset($members[$i]['state']) && strlen(trim($members[$i]['state']))?$members[$i]['state']:''),
							'zip'=>(isset($members[$i]['zip']) && strlen(trim($members[$i]['zip']))?$members[$i]['zip']:'')
						);
						//echo 'starting enroll';
						$result=$this->enrollConsumer($opts);
						//echo 'finished enroll';
						if($result['success']==1){
							$e['report']['individual']['results']['success'][]=array('result'=>$result,'data'=>$opts);
							$misSuccesses++;
							//echo 'starting update';
							$this->updateMember('processed',$members[$i]['id'],$result['api_response']);
							//echo 'finished update';
							unset($this->members['data'][$orderId][$i]);
						}
						else{
							$e['report']['individual']['results']['failure'][]=array('result'=>$result,'data'=>$opts);
							$misFailures++;
						}
						if(count($this->members['data'][$orderId])===0){
							require_once('Order.class.php');
							$order=new Order($this->core,$this->core->user);
							$sql1='UPDATE orders_tbl SET processed=?,active=? WHERE id=?';
							$args1=array(1,1,$orderId);
							$this->core->db->prepare($sql1);
							if($this->core->db->execute($args1)===false){
								$e['response']='An internal error occurred while marking the order as processed.';
								$this->core->recordError('An error occurred in '.__METHOD__,array('sql'=>$sql1,'args'=>$args1,'query'=>$this->core->db->showQuery($sql1,$args1)));
							}
							$this->sendReceipt($order->get('order',array('id'=>$orderId)));
						}
					}
					else{
						break;
					}
				}
				if((microtime(true) - $start) >= (59*60)){
					break;
				}
			}
			if(count($e['report']['individual']['results']['failure'])===0){
				$e['report']['individual']['success']=1;
			}
		}
		else if($this->members['success']==2){
			$e['report']['individual']=array(
				'success'=>1,
				'response'=>'No individual members found that still require processing.'
			);
		}
		else{
			$e['report']['individual']=array(
				'success'=>0,
				'response'=>'An internal error occurred looking up individual members that still require processing.'
			);
		}
		$product=array();
		$this->groups=$this->getUnprocessed('business');
		if($this->groups['success']==1){
			if(isset($this->products['data']['voluntary']) && isset($this->products['data']['voluntary']['Breach Readiness'])){
				$product=$this->products['data']['voluntary']['Breach Readiness'];
			}
			foreach($this->groups['data'] as $orderId=>$orderAr){
				if((microtime(true) - $start) < (59*60)){
					$opts=array(
						'customer_id'=>$product['bus_cust_id'],
						'bundle_id'=>$product['bus_bundle_id'],
						'unique_id'=>$orderAr['guid'],
						'first_name'=>$orderAr['formdata']['first_name'],
						'last_name'=>$orderAr['formdata']['last_name'],
						'email'=>$orderAr['formdata']['email'],
						'phone'=>$orderAr['formdata']['phone'],
						'address'=>$orderAr['formdata']['address'],
						'city'=>$orderAr['formdata']['city'],
						'state'=>$orderAr['formdata']['state'],
						'zip'=>$orderAr['formdata']['zip'],
						'business_name'=>$orderAr['formdata']['business_name'],
					);
					$apiResponse=$this->enrollBusinessAdministrator($opts);
					if($apiResponse['success']==1){
						$e['report']['business']['results']['success'][]=array('result'=>1,'data'=>$opts);
						$misSuccesses++;
						$sql='UPDATE orders_tbl set processed=?,active=? WHERE id=?';
						$args=array(1,1,$orderAr['id']);
						$this->core->db->prepare($sql);
						if($this->core->db->execute($args)!==false){
							require_once('Order.class.php');
							$order=new Order($this->core,$this->core->user);
							$this->sendReceipt($order->get('order',array('id'=>$orderId)));
						}
						else{
							$this->core->recordError('An error occurred in '.__METHOD__,array('sql'=>$sql,'args'=>$args,'query'=>$this->core->db->showQuery($sql,$args)));
							$e['report']['business']['results']['failure'][]=array('result'=>$result,'data'=>$opts);
						}
					}
					else{
						$e['report']['individual']['results']['failure'][]=array('result'=>$apiResponse,'data'=>$opts);
						$misFailures++;
						$this->core->recordError($apiResponse['errors'][0]);
					}
				}
				else{
					break;
				}
			}
			if(count($e['report']['business']['results']['failure'])===0){
				$e['report']['business']['success']=1;
			}
		}
		else if($this->groups['success']==2){
			$e['report']['business']=array(
				'success'=>1,
				'response'=>'No business orders found that still require processing.'
			);
		}
		else{
			$e['report']['business']=array(
				'success'=>0,
				'response'=>'An internal error occurred looking up business orders that still require processing.'
			);
		}
		if($this->groups['success']==1 || $this->members['success']==1){
			$e['report']['complete']['execution_time']=(microtime(true) - $start);
			$e['report']['complete']['success_count']=$misSuccesses;
			$e['report']['complete']['failure_count']=$misFailures;
		}
		else if($this->groups['success']<>0 && $this->members['success']<>0){
			$e['success']=2;
			$e['response']='MIS API ran with no errors, but found nothing to process.';
		}
		if($e['report']['individual']['success']==1 && $e['report']['business']['success']==1){
			$e['success']=1;
		}
		return $e;
	}
	/**
	Process the addition of a member that is part of a new order, or has been added to a census, but not yet enrolled at the provider
	Callable through the public startEnrollment function
	@param array $opts = array of args and settings for API call
	@return array, with keys of 'succcess'(int), 'response'(string), 'api_response'(string)
	*/
	private function enrollConsumer(array $opts):array{
		$e=array('success'=>0,'response'=>'','api_response'=>'');
		$opts=array(
		
		);
		$xmlTemplate=file_get_contents('xml/create-new-user-enrollment.xml');
		$xml=str_replace(
			array(
				'username',
				'password',
				'partner_id',
				'member_id',
				'user_email_address',
				'user_password',
				'ssn',
				'dob',
				'first_name',
				'middle_name',
				'last_name',
				'suffix',
				'address',
				'address_2',
				'city',
				'state',
				'zip',
				'country_code',
				'language_code',
				'phone',
				'phone_type',
				'sms_number',
				'bundle_id',
				'generate_token',
				'error_msg'
			),
			array(
				$this->connectionString['username'],
				$this->connectionString['password'],
				$this->connectionString['partner_id'],
				$this->connectionString['member_id'],
				(isset($opts['user_email_address'])?$opts['user_email_address']:''),
				(isset($opts['user_password'])?$opts['user_password']:''),
				(isset($opts['ssn'])?$opts['ssn']:''),
				(isset($opts['dob'])?$opts['dob']:''),
				(isset($opts['first_name'])?$opts['first_name']:''),
				(isset($opts['middle_name'])?$opts['middle_name']:''),
				(isset($opts['last_name'])?$opts['last_name']:''),
				(isset($opts['suffix'])?$opts['suffix']:''),
				(isset($opts['address'])?$opts['address']:''),
				(isset($opts['address_2'])?$opts['address_2']:''),
				(isset($opts['city'])?$opts['city']:''),
				(isset($opts['state'])?$opts['state']:''),
				(isset($opts['zip'])?$opts['zip']:''),
				(isset($opts['country_code'])?$opts['country_code']:'US'),
				(isset($opts['language_code'])?$opts['language_code']:'EN-US'),
				(isset($opts['phone'])?$opts['phone']:''),
				(isset($opts['phone_type'])?$opts['phone_type']:''),
				(isset($opts['sms_number'])?$opts['sms_number']:''),
				(isset($opts['bundle_id'])?$opts['bundle_id']:''),
				(isset($opts['generate_token'])?$opts['generate_token']:''),
				(isset($opts['error_msg'])?$opts['error_msg']:'')
			),
			$xmlTemplate
		);
		$res=$this->_callAPI($xml);
		$e['api_response']=$res['api_response'];
		if($res['success']==1){
			$xml=str_ireplace(['SOAP-ENV:','SOAP:'],'',$res['api_response']);
			$xml=simplexml_load_string($xml);
			if($xml!==false){
				if($xml->Body->CreateNewUserEnrollmentResponse->CreateNewUserEnrollmentResult==1){
					#TODO: finish afer testing with positive result
				}
				else{
					$e['response']=$xml->Body->CreateNewUserEnrollmentResponse->ErrMsg;
				}
			}
			else{
				$errors=libxml_get_errors();
				$e['response']='Error parsing XML response from API.  Errors given:';
				for($i=0; $i<count($errors); $i++){
					$e['response'].=' '.($i+1)+'. '.$errors[$i];
				}
			}
		}
		else{
			$this->core->recordError('Missing argument cust_id in '.__METHOD__,array('opts'=>$opts));
			$e['response']='Internal error.  API call failed.';
		}
		return $e;
	}
	/**
	Process the removal of a member that has been removed to a census, but not yet cancelled at the provider.
	Callable through the public cancelEnrollment function.
	@param array $opts = array of args and settings for API call
	@return array, with keys of 'succcess'(int), 'response'(string), 'api_response'(string)
	*/
	private function cancelConsumer(array $opts):array{
		$e=array('success'=>0,'response'=>'','api_response'=>'');
		$uri='/api/CancelConsumer';
		$hasCustId=(isset($opts['cust_id']) && strlen(trim($opts['cust_id'])));
		$hasMemberGuid=(isset($opts['member_guid']) && strlen(trim($opts['member_guid'])));
		
		if($hasCustId){
			if($hasMemberGuid){
				$e=$this->_callAPI(
					$uri,
					array(
						'customerID'=>$opts['cust_id'],
						'uniqueID'=>$opts['member_guid']
					)
				);
			}
			else{
				$this->core->recordError('Missing argument member_guid  in '.__METHOD__,array('opts'=>$opts));
				$e['response']='Missing argument order_guid in '.__METHOD__;
			}
		}
		else{
			$this->core->recordError('Missing argument cust_id in '.__METHOD__,array('opts'=>$opts));
			$e['response']='Missing argument cust_id in '.__METHOD__;
		}
		return $e;
	}
	/**
	Process the addition of a member that is part of a new business order, or has been added to a census, but not yet enrolled at the provider.
	Callable through the public startEnrollment function.
	@param array $opts = array of args and settings for API call
	@return array, with keys of 'succcess'(int), 'response'(string), 'api_response'(string)
	*/
	private function enrollBusinessAdministrator(array $opts):array{
		$e=array('success'=>0,'response'=>'');
		$uri='/api/EnrollBusiness';
		$json=array(
			'applicant'=>array(
				'administratorFirstName'=>(isset($opts['first_name']) && strlen(trim($opts['first_name']))?$opts['first_name']:''),
				'administratorLastName'=>(isset($opts['last_name']) && strlen(trim($opts['last_name']))?$opts['last_name']:''),
				'bundleID'=>(isset($opts['bundle_id']) && strlen(trim($opts['bundle_id']))?$opts['bundle_id']:0),
				'businessName'=>(isset($opts['business_name']) && strlen(trim($opts['business_name']))?$opts['business_name']:0),
				'currentAddress'=>array(
					'city'=>(isset($opts['city']) && strlen(trim($opts['city']))?$opts['city']:''),
					'state'=>(isset($opts['state']) && strlen(trim($opts['state']))?$opts['state']:''),
					'streetAddress1'=>(isset($opts['address']) && strlen(trim($opts['address']))?$opts['address']:''),
					'streetAddress2'=>(isset($opts['address_2']) && strlen(trim($opts['address_2']))?$opts['address_2']:''),
					'zipCode'=>(isset($opts['zip']) && strlen(trim($opts['zip']))?$opts['zip']:'')
				),
				'customerID'=>(isset($opts['customer_id']) && strlen(trim($opts['customer_id']))?$opts['customer_id']:0),
				'emailAddress'=>(isset($opts['email']) && strlen(trim($opts['email']))?$opts['email']:''),
				'uniqueID'=>(isset($opts['unique_id']) && strlen(trim($opts['unique_id']))?$opts['unique_id']:''),
				'workPhone'=>(isset($opts['phone']) && strlen(trim($opts['phone']))?$opts['phone']:''),				
			),
			'bundleID'=>(isset($opts['bundle_id']) && strlen(trim($opts['bundle_id']))?$opts['bundle_id']:0),
			'customerID'=>(isset($opts['customer_id']) && strlen(trim($opts['customer_id']))?$opts['customer_id']:0)
		);
		$e=$this->_callAPI($uri,$json);
		return $e;
	}
	/**
	Process the removal of a business order or member that has not yet been cancelled at the provider.
	Callable through the public cancelEnrollment function.
	@param array $opts = array of args and settings for API call
	@return array, with keys of 'succcess'(int), 'response'(string), 'api_response'(string)
	*/
	private function cancelBusiness(array $opts):array{
		$e=array('success'=>0,'response'=>'','api_response'=>'');
		$uri='/api/CancelBusiness';
		$hasCustId=(isset($opts['cust_id']) && strlen(trim($opts['cust_id'])));
		$hasOrderGuid=(isset($opts['order_guid']) && strlen(trim($opts['order_guid'])));
		
		if($hasCustId){
			if($hasOrderGuid){
				$e=$this->_callAPI($uri,array('customerID'=>$opts['cust_id'],'uniqueID'=>$opts['order_guid']));
			}
			else{
				$this->core->recordError('Missing argument order_guid  in '.__METHOD__,array('opts'=>$opts));
				$e['response']='Missing argument order_guid in '.__METHOD__;
			}
		}
		else{
			$this->core->recordError('Missing argument order_guid  in '.__METHOD__,array('opts'=>$opts));
			$e['response']='Missing argument cust_id in '.__METHOD__;
		}
		return $e;
	}
	/**
	Send a receipt if member processing results in a new order with all members processed.
	Sent to both the user who created the order (always), and the end customer, if both exist.
	@param array $order = array of data from the order that was just completed
	@param string $type = string used to determine if this was a new order, all added members processed, or all removed members processed. Valid options are 'order-complete', 'add-members', 'remove-members'
	@return bool, result of the mail send
	*/
	private function sendReceipt(array $order,string $type='order-complete'):bool{
		$return=false;
		if($order['success']==1){
			require_once('Mailer.class.php');
			$mail=Mailer::startMailer();
			if($mail!==NULL){
				$mail->addAddress($order['data']['created_by_email']);
				if($order['data']['created_by_id']!=$order['data']['user_id']){
					$mail->addCC($order['data']['user_email']);
				}
				$message=PHP_EOL.'<h3 style="color:#035C8F; font-size:18px; padding:0px; margin:0px 0px 10px 0px;">Dear '.$order['data']['user_first_name'].' ' .$order['data']['user_last_name'].',</h3>';
				if($type=='order-complete'){
					$mail->Subject='Your Order with '.$this->core->opts['site_title'].' has been completed';
					$message.=PHP_EOL.'
						<p style="font-size:13px;">Your order has been completed and emails are going out for individual enrollment. You can view this order any time 
						at <a href="'.$this->core->opts['host'].'/account/mybusiness/order/'.$order['data']['guid'].'">'.$this->core->opts['host'].'/account/mybusiness/order/'.$order['data']['guid'].'</a>.</p>
					';
				}
				else if($type=='add-members'){
					$mail->Subject='New Members Enrollment complete for your order with '.$this->core->opts['site_title'].'.';
					$message.=PHP_EOL.'
						<p style="font-size:13px;">All new members have been processed and emails are going out for individual enrollment. You can view this order any time 
						at <a href="'.$this->core->opts['host'].'/account/mybusiness/order/'.$order['data']['guid'].'">'.$this->core->opts['host'].'/account/mybusiness/order/'.$order['data']['guid'].'</a>.</p>
					';
				}
				else{
					$mail->Subject='Removed Members Cancellation complete for your order with '.$this->core->opts['site_title'].'.';
					$message.=PHP_EOL.'
						<p style="font-size:13px;">All removed members have been processed and their policies have been marked for cancellation on the next upcoming renewal date. You can view this order any time 
						at <a href="'.$this->core->opts['host'].'/account/mybusiness/order/'.$order['data']['guid'].'">'.$this->core->opts['host'].'/account/mybusiness/order/'.$order['data']['guid'].'</a>.</p>
					';
				}
				$message.=PHP_EOL.'<p style="font-size:13px;">Again, we thank you for taking action to protect your team.  Please do not hesitate to reach out to our <a href="mailto:'.$this->core->opts['sales_email'].'">sales team</a> with any questions you may have.</p>';
				$message.=PHP_EOL.'<p style="font-size:13px;">Thank you,<br />'.$this->core->opts['site_title'].'</p>';
				$mail->Body=$this->core->buildHtmlEmail($message.PHP_EOL.$this->core->emailDisclaimer);
				try{
					if($mail->send()){
						$return=true;
					}
				}
				catch(Exception $ex){
					echo 'An PHPMailer Exception occurred sending the receipt.';
					$this->core->recordError('Mailer Exception in '.__METHOD__.': ' . $ex->getMessage().'<br />'.$ex->getTraceAsString());
				}
			}
			else{
				echo 'An internal error occurred sending the receipt.';
				$this->core->recordError('Mailer Error in '.__METHOD__.': PHPMailer failed to initialize.');
			}
		}
		else{
			echo 'Order lookup failed.';
		}
		return $return;
	}
	/**
	The guts of this class.  The only function that can interact with the API directly.
	Uses Curl to connect to the endpoint provided by $uri parameter.
	Curl response, is interpreted, and normalized to a boolean result response.
	Raw response is returned in return array's api_reponse key and should be stored with the corresponding record.
	@param string $uri = endpoint of the API to call
	@param array $dataAr = array of data and settings for the API call
	@return array, with keys of 'succcess'(int), 'response'(string), 'api_response'(string), 'data'(array)
	*/
	private function _callAPI(string $xml):array{
		$r=array('success'=>0,'response'=>'','api_response'=>'');
		$curl=curl_init();
		ob_start();
		$out=fopen('php://output','w');
		curl_setopt_array($curl, array(
			CURLOPT_URL => $this->connectionString['host'],
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_ENCODING => '',
			CURLOPT_MAXREDIRS => 10,
			CURLOPT_TIMEOUT => 0,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
			CURLOPT_CUSTOMREQUEST => 'POST',
			CURLOPT_POSTFIELDS =>$xml,
			CURLOPT_HTTPHEADER => array(
				'Content-Type: text/xml'
			)
		));
		$response=curl_exec($curl);
		$r['api_response']=$response;
		if($response!==false){
			$r['success']=1;
		}
		else{
			$curlError=curl_error($curl);
			$debug=ob_get_clean();
			$r['response']=$curlError.(strlen(trim($debug))?'<br />'.PHP_EOL.$debug:'');
			$this->processApiError(__METHOD__,$r,curl_errno($curl),$curlError,array('xml'=>$xml));
		}
		curl_close($curl);
		return $r;
	}
	/**
	Helper function for all API calls. Builds and records an API error report in the DB.
	@param string $method = method that triggered the error.
	@param array &$e = passed by reference response array from calling method.
	@param string $errorNum = Curl error number returned from API call
	@param array $errorMessages = array of error messages returned by the Authorize SDK.
	@param array $releventData = array of data used in the API call.
	@return void
	*/
	private function processApiError(string $method,array &$e,int $errorNum, string $errorMessage,array $releventData=array()):void{
		$e['response']=$errorMessage;
		$e['error_code']=$errorNum;
		$this->core->recordApiError($method,$errorNum,$e['api_response'],$errorMessage,$releventData);
	}
	/**
	Update a member record in the database after a successful add or remove through the API.
	@param string $type = Used to dtermine whether this was an add or remove.  Valid options are 'processed', 'removed'
	@param array $dataAr = string used to detrmine if this was a new order, all added members processed, or all removed members processed
	@return array, with keys of 'succcess'(int), 'response'(string), 'data'(array)
	*/
	private function updateMember(string $type,int $memberId,string $apiResponse):array{
		$r=array('success'=>0,'response'=>'','data'=>array());
		$sql='UPDATE orders_x_census_members_tbl SET ';
		$args=array();
		if($type=='processed'){
			$sql.='processed=?,processed_response=?,processed_date=NOW()';
			$args=array(1,$apiResponse);
		}
		else{
			$sql.='delete_processed=?,delete_response=?,delete_processed_date=NOW()';
			$args=array(1,$apiResponse);
		}
		$sql.=' WHERE id=?';
		$args[]=$memberId;
		$this->core->db->prepare($sql);
		if($this->core->db->execute($args)!==false){
			$r['success']=1;
		}
		else{
			$this->core->recordError('An error occurred in '.__METHOD__,array('sql'=>$sql,'args'=>$args,'query'=>$this->core->db->showQuery($sql,$args)));
			$r['response']='An internal error occurred. '.$this->core->publicError;
		}
		return $r;
	}
	/**
	Get the product settings from the MIS settings table. Callable from the public get function.
	@param array $opts = optional array of options for the search
	@return array, with keys of 'succcess'(int), 'response'(string), 'data'(array)
	*/
	private function getProducts(array $opts=array()):array{
		$r=array('success'=>0,'response'=>'','data'=>array());
		$sql='SELECT * FROM enfortra_settings_tbl WHERE active=?';
		$args=array(1);
		$this->core->db->prepare($sql);
		if($this->core->db->execute($args)!==false){
			$temp=$this->core->db->fetchAll();
			if(is_array($temp) && count($temp)>0){
				$tempKeys=array_unique(array_column($temp,'payment_type'));
				foreach($tempKeys as $k){
					$r['data'][$k]=array();
				}
				foreach($temp as $t){
					$r['data'][$t['payment_type']][$t['product_name']]=$t;
				}
				$r['success']=1;
			}
			else{
				$r['response']='No plans could not be found. '.$this->core->publicError;
			}
		}
		else{
			$this->core->recordError('An error occurred in '.__METHOD__,array('sql'=>$sql,'args'=>$args,'query'=>$this->core->db->showQuery($sql,$args)));
			$r['response']='An internal error occurred. '.$this->core->publicError;
		}
		return $r;
	}
	/**
	Get a product's setting from the MIS settings table. Callable from the public get function.
	@param array $opts = array of options for the search.  Must contain a key of id or product_name to be a valid request.  Optional key of payment_type can narrow results.
	@return array, with keys of 'succcess'(int), 'response'(string), 'data'(array)
	*/
	private function getProduct(array $opts):array{
		$r=array('success'=>0,'response'=>'','data'=>array());
		$sql='';
		$args=array();
		$hasId=isset($opts['id']) && strlen(trim($opts['id']));
		$hasName=isset($opts['product_name']) && strlen(trim($opts['product_name']));
		if($hasId || $hasName){
			if($hasId){
				$sql='SELECT * FROM mis_settings_tbl WHERE id=?';
				$args[]=$opts['id'];
			}
			else{
				$sql='SELECT * FROM mis_settings_tbl WHERE product_name=?';
				$args[]=$opts['product_name'];
			}
			if(isset($opts['payment_type']) && strlen(trim($opts['payment_type']))){
				$sql.=' AND payment_type=?';
				$args[]=$opts['payment_type'];
			}
			$this->core->db->prepare($sql);
			if($this->core->db->execute($args)!==false){
				$temp=$this->core->db->fetch();
				if(is_array($temp) && count($temp)>0){
					$r['data']=$temp;
					$r['success']=1;
				}
				else{
					$r['response']='That Product could not be found. '.$this->core->publicError;
				}
			}
			else{
				$this->core->recordError('An error occurred in '.__METHOD__,array('sql'=>$sql,'args'=>$args,'query'=>$this->core->db->showQuery($sql,$args)));
				$r['response']='An internal error occurred. '.$this->core->publicError;
			}
		}
		else{
			$r['response']='An internal error occurred. Missing parameter to '.__METHOD__.'. '.$this->core->publicError;
		}
		return $r;
	}
	/**
	Get all members who required cancellation at the provider.  Members who have been processed and deleted in the db.
	Helper function for processRemovedMembers function.
	@return array, with keys of 'succcess'(int), 'response'(string), 'data'(array)
	*/
	private function getDeletedMembers():array{
		$r=array('success'=>0,'response'=>'','data'=>array());
		$sql='SELECT * FROM orders_x_census_members_view WHERE processed=? AND deleted=? AND delete_processed<>? AND (member_delete_effective_date IS NULL OR member_delete_effective_date<=NOW()) ORDER BY order_id,date_created';
		$args=array(1,1,1);
		$this->core->db->prepare($sql);
		if($this->core->db->execute($args)!==false){
			$temp=$this->core->db->fetchAll();
			if(is_array($temp) && count($temp)>0){
				foreach($temp as $t){
					if(!isset($r['data'][$t['order_id']])){
						$r['data'][$t['order_id']]=array();
					}
					$r['data'][$t['order_id']][]=$t;
				}
				$r['success']=1;
			}
			else{
				$r['response']='No unprocessed census members marked as deleted found.';
				$r['success']=2;
			}
		}
		else{
			$this->core->recordError('An error occurred in '.__METHOD__,array('sql'=>$sql,'args'=>$args,'query'=>$this->core->db->showQuery($sql,$args)));
			$r['response']='An internal error occurred. '.$this->core->publicError;
		}
		return $r;
	}
	/**
	Get all members who required enrollment at the provider.  Members who have reached or passed the effective date, order is complete, and have not either been processed or deleted.
	Helper  function for the exportOrders function.
	@param string $type = string to determine which type of members to retrieve.  Valid keys are 'consumer or 'business'
	@return array, with keys of 'succcess'(int), 'response'(string), 'data'(array)
	*/
	private function getUnprocessed(string $type='members'):array{
		$r=array('success'=>0,'response'=>'','data'=>array());
		if($type=='members'){
			$sql='SELECT * FROM orders_x_census_members_view WHERE effective_date<=? AND order_approved=? AND processed=? AND deleted<>? AND (member_effective_date IS NULL OR member_effective_date<=NOW()) ORDER BY order_id,date_created';
			$args=array(date('Y-m-d'),1,0,1);
			$this->core->db->prepare($sql);
			if($this->core->db->execute($args)!==false){
				$temp=$this->core->db->fetchAll();
				if(is_array($temp) && count($temp)>0){
					foreach($temp as $t){
						if(!isset($r['data'][$t['order_id']])){
							$r['data'][$t['order_id']]=array();
						}
						$r['data'][$t['order_id']][]=$t;
					}
					$r['success']=1;
				}
				else{
					$r['response']='No unprocessed census members found.';
					$r['success']=2;
				}
			}
			else{
				$this->core->recordError('An error occurred in '.__METHOD__,array('sql'=>$sql,'args'=>$args,'query'=>$this->core->db->showQuery($sql,$args)));
				$r['response']='An internal error occurred. '.$this->core->publicError;
			}
		}
		else if($type='business'){
			$sql='SELECT id,guid,plan_id,subscription_id,product_name,group_type,payment_source FROM orders_view WHERE effective_date<=? AND processed=? AND group_type=?';
			$args=array(date('Y-m-d'),0,ucwords($type));
			$this->core->db->prepare($sql);
			if($this->core->db->execute($args)!==false){
				$temp=$this->core->db->fetchAll();
				if(is_array($temp) && count($temp)>0){
					foreach($temp as $t){
						$r['data'][$t['id']]=$t;
					}
					$orderIds=array_values(array_column($temp,'id'));
					$args=array();
					$sql='SELECT * FROM orders_x_business_members_tbl WHERE order_id IN(';
					foreach($orderIds as $id){
						$sql.='?,';
						$args[]=$id;
					}
					$sql=rtrim($sql,',').')';
					$this->core->db->prepare($sql);
					if($this->core->db->execute($args)!==false){
						$temp=$this->core->db->fetchAll();
						if(is_array($temp) && count($temp)===count($r['data'])){
							foreach($temp as $t){
								$r['data'][$t['order_id']]['formdata']=$t;
							}
							$r['success']=1;
						}
						else{
							$r['response']='No unprocessed business members found.';
							$r['success']=2;
						}
					}
					else{
						$this->core->recordError('An error occurred in '.__METHOD__,array('sql'=>$sql,'args'=>$args,'query'=>$this->core->db->showQuery($sql,$args)));
						$r['response']='An internal error occurred. '.$this->core->publicError;
					}
				}
				else{
					$r['response']='No unprocessed business members found.';
					$r['success']=2;
				}
			}
			else{
				$this->core->recordError('An error occurred in '.__METHOD__,array('sql'=>$sql,'args'=>$args,'query'=>$this->core->db->showQuery($sql,$args)));
				$r['response']='An internal error occurred. '.$this->core->publicError;
			}
		}
		return $r;
	}
	/**
	Retrieve and map the keys and data required for the API call.
	@param array $memberAr = array of data about the member.  Specifically 'id', 'type', 'group_type', 'product_name',
	@return array, with keys of succcess(int), response(string), 'skipped'(string), customer_id'(string), 'bundle_id'(string)
	*/
	private function getAPIArgs(array $memberAr):array{
		$r=array('success'=>0,'response'=>'','skipped'=>'','seq_id'=>NULL,'bundle_id'=>NULL);
		if($this->products['success']==1){
			if(isset($this->products['data'][$memberAr['payment_type']][$memberAr['product_name']])){
				$r['bundle_id']=$this->products['data'][$memberAr['payment_type']][$memberAr['product_name']]['bundle_id'];
				$r['seq_id']=$this->products['data'][$memberAr['payment_type']][$memberAr['product_name']]['seq_id'];
				$r['success']=1;
			}
			else{
				$r['response']='An internal error occurred with Product match to API args.';
				$r['skipped']=$memberAr['id'].':'.$this->products['data'][$memberAr['payment_type']][$memberAr['product_name']];
			}
		}
		else{
			$r['response']='An internal error occurred with Products lookup.';
		}
		return $r;
	}
	/**
	Retrieve the connection String to the MIS API.
	Helper function for the  __construct function.
	@return bool, only true if valid connection string was found and parsed successfully
	*/
	private function _getConnectionSettings():bool{
		$return=false;
		$path=dirname($this->core->opts['base_dir']).'/'.($this->core->opts['debug']=='1'?$this->debugConnectFile:$this->connectFile);
		if(file_exists($path)){
			$t=file_get_contents($path);
			if(strlen(trim($t))){
				$c=explode('|||',$t);
				if(is_array($c) && count($c)===5){
					$this->connectionString=array('host'=>$c[0],'username'=>$c[1],'password'=>$c[2],'partner_id'=>$c[3],'member_name'=>$c[4]);
					$return=true;
				}
				else{
					$this->core->recordError('Connection file was corrupt in '.__METHOD__);
				}
			}
			else{
				$this->core->recordError('Connection file was blank '.__METHOD__);
			}
		}
		else{
			$this->core->recordError('Connection file was not found in '.__METHOD__);
		}
		return $return;
	}
	/*
	public function testConnectivity(){
		$response=NULL;
		$curl = curl_init();
		curl_setopt_array($curl, array(
			CURLOPT_URL => $this->connectionString['host'].'/api/TestConnectivity',
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_ENCODING => "",
			CURLOPT_MAXREDIRS => 10,
			CURLOPT_TIMEOUT => 30,
			CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
			CURLOPT_CUSTOMREQUEST => "GET",
			CURLOPT_POSTFIELDS => "",
			CURLOPT_INTERFACE => $_SERVER['SERVER_ADDR'],
			CURLOPT_HTTPHEADER => array(
				"Authorization: Basic ".base64_encode($this->connectionString['username'].':'.$this->connectionString['password']),
				"Content-Type: application/x-www-form-urlencoded",
				"cache-control: no-cache"
			),
		));
		$response=curl_exec($curl);
		$err=curl_error($curl);
		curl_close($curl);
		if($err){
			$response=$err;
		}
		return $response;
	}
	*/
}
?>