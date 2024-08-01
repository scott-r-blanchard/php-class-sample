<?
require_once('ProviderApiInterface.class.php');
/**
Handles all API interactions with the Merchants Info System's(MIS) API. https://merchantsinfo.com/.
Usually not instantiated directly.  Called by ProviderApi.class.php. Check cron daemons for exceptions with direct instantiating.
@implements ProviderApiInterface
@copyright: 2024 defend-id
@author: Scott Blanchard
@version 2.0 - 7/2024
*/
final class MISApi implements ProviderApiInterface{
	/**
	Stores an instance of a Core class object passed to $this->__construct() by reference
	*/
	private $core=NULL;
	/**
	Filename of the config file for live server connections.
	*/
	private $connectFile='mis-api.ini';
	/**
	Filename of the config file for dev or debug server connections.
	*/
	private $debugConnectFile='mis-api-debug.ini';
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
	Stores an instance of a passed by reference Core object to an internal member.
	Verifies connection string to API can be found and read.
	Initializes Products object
	@param Core $core = passed by reference site Core object
	@param bool $testRun = flag for specific developer test runs
	@return void
	*/
	function __construct(Core &$core,bool $testRun=false){
		$this->core=$core;
		if(!$this->_getConnectionString()){
			die('FATAL ERROR: PROVIDER API CORE FAILED TO INITIALIZE.  CHECK ERROR LOGS FOR MORE INFORMATION');
		}
		$this->products=$this->getProducts();
		/*
		if($testRun){
			$sql='SELECT * FROM `orders_x_census_members_view` WHERE order_id=? AND deleted<>? order by first_name,last_name;';
			$args=array(8,1);
			$this->core->db->prepare($sql);
			$this->core->db->execute($args);
			$dbMembers=$this->core->db->fetchAll();
			//echo count($dbMembers).PHP_EOL;
			$sql='SELECT * FROM temp_cbia_members_tbl ORDER BY first_name,last_name';
			$args=array();
			$this->core->db->prepare($sql);
			$this->core->db->execute($args);
			$cbiaMembers=$this->core->db->fetchAll();
			//echo count($cbiaMembers).PHP_EOL;
			$mismatch=0;
			for($i=21500; $i<count($dbMembers); $i+=1){
				if(strtolower($dbMembers[$i]['first_name'].$dbMembers[$i]['last_name'])!=strtolower($cbiaMembers[$i]['first_name'].$cbiaMembers[$i]['last_name'])){
					$mismatch=$i;
					echo 'db:'.$dbMembers[$i]['first_name'].' '.$dbMembers[$i]['last_name'].PHP_EOL;
					echo 'cbia:'.$cbiaMembers[$i]['first_name'].' '.$cbiaMembers[$i]['last_name'].PHP_EOL;
					break;
				}
			}
			for($i=100; $i<count($dbMembers); $i+=100){
				if(strtolower($dbMembers[$i]['first_name'].$dbMembers[$i]['last_name'])!=strtolower($cbiaMembers[$i]['first_name'].$cbiaMembers[$i]['last_name'])){
					$mismatch=$i;
					break;
				}
			}
			echo $mismatch;
			die;
			$report=array('success_count'=>0,'fail_count'=>0,'successes'=>array(),'fails'=>array());
			$sql='SELECT * FROM `temp_mis_cbia_tbl` WHERE guid NOT IN (SELECT member_guid FROM orders_x_census_members_view WHERE order_id=?);';
			$args=array(8);
			$this->core->db->prepare($sql);
			$this->core->db->execute($args);
			$removes=$this->core->db->fetchAll(0);
			foreach($removes as $remove){
				$cancelResult=$this->cancelConsumer(array('cust_id'=>'200400','member_guid'=>$remove['guid']));
				print_r($cancelResult);
				if($cancelResult['success']==1){
					$report['success_count']++;
					$report['successes'][$remove['guid']]=$cancelResult;
				}
				else{
					$report['fail_count']++;
					$report['fails'][$remove['guid']]=$cancelResult;
				}
			}
			print_r($report);
			die;
			//print_r($removes);
			//die;
		}
		*/
		
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
	/**
	Process members that have been removed from a census, but not yet cancelled at the provider.
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
	Run by cron daemon hourly.  This API is SLOW, so it may not finish its job within the hour limit on large orders.  Sets timer to stop itself at 59 minutes to avoid overlap and race conditions.
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
						$result=$this->enrollConsumer($opts);
						if($result['success']==1){
							$e['report']['individual']['results']['success'][]=array('result'=>$result,'data'=>$opts);
							$misSuccesses++;
							$this->updateMember('processed',$members[$i]['id'],$result['api_response']);
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
		$uri='/api/EnrollConsumer';
		$json=array(
			'applicant'=>array(
				'bundleID'=>(isset($opts['bundle_id']) && strlen(trim($opts['bundle_id']))?$opts['bundle_id']:0),
				'currentAddress'=>array(
					'city'=>(isset($opts['city']) && strlen(trim($opts['city']))?$opts['city']:''),
					'state'=>(isset($opts['state']) && strlen(trim($opts['state']))?$opts['state']:''),
					'streetAddress1'=>(isset($opts['address']) && strlen(trim($opts['address']))?$opts['address']:''),
					'streetAddress2'=>(isset($opts['address_2']) && strlen(trim($opts['address_2']))?$opts['address_2']:''),
					'zipCode'=>(isset($opts['zip']) && strlen(trim($opts['zip']))?$opts['zip']:'')
				),
				'customerID'=>(isset($opts['customer_id']) && strlen(trim($opts['customer_id']))?$opts['customer_id']:0),
				'firstName'=>(isset($opts['first_name']) && strlen(trim($opts['first_name']))?$opts['first_name']:''),
				'homePhone'=>(isset($opts['phone']) && strlen(trim($opts['phone']))?$opts['phone']:''),
				'lastName'=>(isset($opts['last_name']) && strlen(trim($opts['last_name']))?$opts['last_name']:''),
				'primaryEmail'=>(isset($opts['email']) && strlen(trim($opts['email']))?$opts['email']:''),
				'uniqueID'=>(isset($opts['unique_id']) && strlen(trim($opts['unique_id']))?$opts['unique_id']:'')
			),
			'bundleID'=>(isset($opts['bundle_id']) && strlen(trim($opts['bundle_id']))?$opts['bundle_id']:0),
			'customerID'=>(isset($opts['customer_id']) && strlen(trim($opts['customer_id']))?$opts['customer_id']:0)
		);
		$e=$this->_callAPI($uri,$json);
		return $e;
	}
	/**
	Process the removal of a member that has been removed from a census, but not yet cancelled at the provider.
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
				$this->core->recordError($e['response']='Missing argument member_guid  in '.__METHOD__,array('opts'=>$opts));
				$e['response']='Missing argument order_guid in '.__METHOD__;
			}
		}
		else{
			$this->core->recordError($e['response']='Missing argument cust_id in '.__METHOD__,array('opts'=>$opts));
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
				$this->core->recordError($e['response']='Missing argument order_guid  in '.__METHOD__,array('opts'=>$opts));
				$e['response']='Missing argument order_guid in '.__METHOD__;
			}
		}
		else{
			$this->core->recordError($e['response']='Missing argument order_guid  in '.__METHOD__,array('opts'=>$opts));
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
				$message.=PHP_EOL.'<p style="font-size:13px;">Again, we thank you for taking action to protect your team.  Please do not hesitate to reach out to our <a href="mailto:sales@defend-id.com">sales team</a> with any questions you may have.</p>';
				$message.=PHP_EOL.'<p style="font-size:13px;">Thank you,<br />defend-id</p>';
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
	Curl response is interpreted and normalized to a standard result response.
	Raw response is returned in return array's api_reponse key and should be stored with the corresponding record for audit/debug purposes.
	@param string $uri = endpoint of the API to call
	@param array $dataAr = array of data and settings for the API call
	@return array, with keys of 'succcess'(int), 'response'(string), 'api_response'(string), 'data'(array)
	*/
	private function _callAPI(string $uri,array $dataAr):array{
		$r=array('success'=>0,'response'=>'','api_response'=>'','data'=>array());
		$curl=curl_init();
		ob_start();
		$out=fopen('php://output','w');
		curl_setopt_array($curl,array(
			CURLOPT_URL=>$this->connectionString['host'].$uri,
			CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,
			CURLOPT_HEADER=>0,
			CURLOPT_CONNECTTIMEOUT=>30,
			CURLOPT_TIMEOUT=>30,
			CURLOPT_POST=>true,
			CURLOPT_VERBOSE=>false,
			CURLOPT_STDERR=>$out,
			CURLOPT_INTERFACE => '216.146.199.32',
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_ENCODING => "",
			CURLOPT_MAXREDIRS => 10,
			CURLOPT_TIMEOUT => 30,
			CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
			CURLOPT_POSTFIELDS => json_encode($dataAr),
			CURLOPT_HTTPHEADER => array(
				"Authorization: Basic ".base64_encode($this->connectionString['username'].':'.$this->connectionString['password']),
				"Content-Type: application/json",
				"cache-control: no-cache"
			)
		));
		$response=curl_exec($curl);
		if($response!==false){
			$r['data']=json_decode($response,true);
			$r['api_response']=$response;
			curl_close($curl);
			if(isset($r['data']['success']) && (bool)$r['data']['success']===true){
				$r['success']=1;
				ob_end_flush();
			}
			else{
				$curlError=curl_error($curl);
				$debug=ob_get_clean();
				$r['response']=$curlError.(strlen(trim($debug))?'<br />'.PHP_EOL.$debug:'');
				$this->processApiError(__METHOD__,$r,curl_errno($curl),$curlError,$dataAr);
			}
		}
		else{
			$curlError=curl_error($curl);
			$debug=ob_get_clean();
			$r['response']=$curlError.(strlen(trim($debug))?'<br />'.PHP_EOL.$debug:'');
			$this->processApiError(__METHOD__,$r,curl_errno($curl),$curlError,$dataAr);
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
		$sql='SELECT * FROM mis_settings_tbl WHERE active=?';
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
	Get all members who required cancellation at the provider.  Members who have been processed and deleted in the db, but not processed through the API.
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
		$r=array('success'=>0,'response'=>'','skipped'=>'','customer_id'=>NULL,'bundle_id'=>NULL);
		if($this->products['success']==1){
			if(isset($this->products['data'][$memberAr['type']][$memberAr['product_name']])){
				$r['customer_id']=$this->products['data'][$memberAr['type']][$memberAr['product_name']]['cust_id'];
				if((isset($this->products['data'][$memberAr['type']][$memberAr['product_name']]['individual_bundle_id']) && strlen(trim($this->products['data'][$memberAr['type']][$memberAr['product_name']]['individual_bundle_id']))) || (isset($this->products['data'][$memberAr['type']][$memberAr['product_name']]['family_bundle_id']) && strlen(trim($this->products['data'][$memberAr['type']][$memberAr['product_name']]['family_bundle_id'])))){
					if($memberAr['group_type']=='Individual'){
						$r['bundle_id']=$this->products['data'][$memberAr['type']][$memberAr['product_name']]['individual_bundle_id'];
					}
					else{
						$r['bundle_id']=$this->products['data'][$memberAr['type']][$memberAr['product_name']]['family_bundle_id'];
					}
					$r['success']=1;
				}
				else{
					$r['response']='An internal error occurred with Product match to API args.';
				}
			}
			else{
				$r['response']='An internal error occurred with Product match to API args.';
				$r['skipped']=$memberAr['id'].':'.$this->products['data'][$memberAr['type']][$memberAr['product_name']];
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
	private function _getConnectionString():bool{
		$return=false;
		$path=dirname($this->core->opts['base_dir']).'/'.($this->core->opts['debug']=='1'?$this->debugConnectFile:$this->connectFile);
		if(file_exists($path)){
			$t=file_get_contents($path);
			if(strlen(trim($t))){
				$c=explode('|||',$t);
				if(is_array($c) && count($c)===3){
					$this->connectionString=array('host'=>$c[0],'username'=>$c[1],'password'=>$c[2]);
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
}
?>