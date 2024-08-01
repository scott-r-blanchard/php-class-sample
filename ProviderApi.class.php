<?
require_once('ProviderApiInterface.class.php');
/**
Handles all Provider API interactions.
Has no API capabilities itself.  Acts as a gateway to the individual provider's API.
Normalizes what could be very different API/SDK/File Feed processes for various providers.
No actions.  This is just a data retriever.
@implements ProviderApiInterface, as must any Provider API class that plans to use this as the gateway
@copyright: 2024 defend-id
@author: Scott Blanchard
*/
final class ProviderApi implements ProviderApiInterface{
	/**
	Stores an instance of a Core class object passed to $this->__construct() by reference
	*/
	private $core=NULL;
	/**
	Stores an instance of the current specific Provider's API integration class.
	*/
	private $providerAPI=NULL;
	/**
	Adds the passed Core object to an internal property.
	If the optional $providerId argument is passed, also looks up and assigns that provider as the current one.
	@param Core $core = passed by reference site Core object
	$param int providerId = optional id of the Provider to run this class as.  This can be altered on the fly through the set function
	@return void
	*/
	function __construct(Core &$core,int $providerId=NULL){
		$this->core=$core;
		if($providerId!=NULL){
			$this->_setProvider($providerId);
		}
	}
	/**
	Public gateway function to various private data retrieval functions.
	@param string $what = type of data to retrieve. Valid options currently come from the end Provider API class.  If no provider set, this function defaults to a failure
	@param array $opts = optional array of args and settings for the data retrieval
	@return array, typically with keys of 'succcess'(int), 'response'(string), 'data'(array)
	*/
	public function get(string $what,array $opts=array()):array{
		$return=array('success'=>0,'response'=>'Invalid argument passed to '.__METHOD__);
		if($this->providerAPI!=NULL){
			$return=$this->providerAPI->get($what,$opts);
		}
		else{
			switch($what){
				default:
					$this->core->recordError('Invalid type passed to '.__METHOD__);
			}
		}
		return $return;
	}
	/**
	Public gateway to set various private class properties.
	@param string $what = name of class property to update.  Currently only accepts 'provider'
	@param array $opts = optional array of args and settings class member update
	@return bool
	*/
	public function set(string $what,array $opts=array()):bool{
		$return=false;
		switch($what){
			case 'provider':
				if(isset($opts['id'])){
					$setResult=$this->_setProvider($opts['id']);
					if($setResult['success']==1){
						$return=true;
					}
				}
				break;
			default:
				$return=$this->providerAPI->set($what,$opts);
		}
		return $return;
	}
	/**
	Public gateway to run various private Provider API calls and other data processing functions.
	Required function for this class to work with ProviderApiInterface.
	Exclusively called by service daemons.  Uses print_r() to force an email to head dev in case of error.
	@param string $what = type of API function to perform.  Curent valid options are 'export-orders', 'process-removed-members'.  Anything else is just passed along to the Provider API class to see if it had an action for that key
	@param array $opts = optional array of args and settings for processing function
	@return array, typically with keys of 'succcess'(int), 'response'(string), 'report'(array)
	*/
	public function run(string $what,array $opts=array()):array{
		$e=array('success'=>0,'response'=>'');
		if($what=='export-orders'){
			$providers=$this->_getProviders(array('needs_processing'=>true));
			if($providers['success']==1){
				foreach($providers['data'] as $id){
					$this->_setProvider($id);
					$e=$this->providerAPI->run('export-orders');
					$this->recordResult(json_encode($e),get_class($this->providerAPI));
				}
			}
			else{
				$e['response']='There were no Providers found with orders that still require exporting.';
				$e['success']=1;
			}
		}
		else if($what=='process-removed-members'){
			$providers=$this->_getProviders(array('needs_removal'=>true));
			if($providers['success']==1){
				foreach($providers['data'] as $id){
					$this->_setProvider($id);
					$e=$this->providerAPI->run('process-removed-members');
					$this->recordResult(json_encode($e),get_class($this->providerAPI));
				}
			}
			else{
				$e['response']='There were no Providers found with orders that still require exporting.';
				$e['success']=1;
			}
		}
		else if($this->providerAPI!=NULL){
			$e=$this->providerAPI->run($what,$opts);
		}
		else{
			switch($what){
				default:
					$e['response']='Invalid argument passed to '.__METHOD__;
			}
		}
		if($e['success']==0){
			print_r($e);
		}
		return $e;
	}
	/**
	Public gateway to private Provider API start enrollment functions.
	Required function for this class to work with ProviderApiInterface.
	@param string $type = type of API enrollment function to perform. Passed along to rovider API class if Provider is set
	@param array $opts = array of args and settings for processing function
	@return array, with keys of 'succcess'(int), 'response'(string), 'api_response'(string)
	*/
	public function startEnrollment(string $type, array $opts):array{
		$e=array('success'=>0,'response'=>'','api_response'=>'');
		if($this->providerAPI!=NULL){
			$e=$this->providerAPI->startEnrollment($type,$opts);
		}
		else{
			switch($what){
				default:
					$e['response']='Provider API failed to initialize.';
			}
		}
		return $e;
	}
	/**
	Public gateway to private Provider API cancel enrollment functions.
	Required function for this class to work with ProviderApiInterface.
	@param string $type = type of API cancellation function to perform.
	@param array $opts = array of args and settings for processing function
	@return array, with keys of 'succcess'(int), 'response'(string), 'api_response'(string)
	*/
	public function cancelEnrollment(string $type, array $opts):array{
		$e=array('success'=>0,'response'=>'','api_response'=>'');
		if($this->providerAPI!=NULL){
			$e=$this->providerAPI->cancelEnrollment($type,$opts);
		}
		else{
			switch($what){
				default:
					$e['response']='Invalid argument passed to '.__METHOD__;
			}
		}
		return $e;
	}
	/**
	Record the result of any Provider API call in a log file.
	Callable through the public run function.
	@param string json = JSON encoded string of the run report
	@param string apiClassName = name of the end Prover API class that was actually executed by the run function
	@return array, with keys of 'succcess'(int), 'response'(string)
	*/
	private function recordResult(string $json,string $apiClassName):array{
		$e=array('success'=>0,'response'=>'');
		$h=false;
		if(($h=fopen($this->core->opts['provider_api_logs_dir'].$apiClassName.'-'.date('Ymd-His').'.json','w+'))!==false){
			fwrite($h,$json,strlen($json));
			fclose($h);
			$e['success']=1;
		}
		else{
			$e['response']='An internal error occurred creating the file for writing.';
		}
		return $e;
	}
	/**
	Set the internal providerId property if a Provider can be found based on the id parameter
	Callable through the public set function.  Also used as a helper function internally.
	@param int id = Id of the Provider to set to
	@return array, with keys of 'succcess'(int), 'response'(string)
	*/
	private function _setProvider(int $id):array{
		$e=array('success'=>0,'response'=>'');
		$tempResult=$this->_getProvider($id);
		if($tempResult['success']==1){
			require_once($tempResult['class'].'.class.php');
			$this->providerAPI=new $tempResult['class']($this->core);
			$e['success']=1;
		}
		else{
			$e['response']='An internal error occurred. The Provider record could not be found. '.$this->core->publicError;
			die('FATAL ERROR: PROVIDER CORE FAILED TO INITIALIZE.  CHECK ERROR LOGS FOR MORE INFORMATION');
		}
		return $e;
	}
	/**
	Get details on the currently assigned provider defined by the Class providerId property.
	Most important use of this is to dynamically determine which class file to load to run the Provider API.
	Helper function for this class only.  Not callable by instance or inheritance.
	@return array, with keys of 'succcess'(int), 'response'(string), 'class'(string)
	*/
	private function _getProvider(int $id):array{
		$e=array('success'=>0,'response'=>'','class'=>'');
		$sql='SELECT * FROM providers_tbl WHERE id=?';
		$args=array($id);
		$this->core->db->prepare($sql);
		if($this->core->db->execute($args)!==false){
			$t=$this->core->db->fetch();
			if(is_array($t) && count($t)>0){
				if($t['conn_type']=='api'){
					if(isset($t['conn_class']) && strlen(trim($t['conn_class']))){
						$e['success']=1;
						$e['class']=$t['conn_class'];
					}
					else{
						$e['response']='An internal error occurred assigning the provider connection.  Provider API was missing. '.$this->core->publicError;
					}
				}
				else{
					$e['response']='An internal error occurred assigning the provider connection.  Provider does not use an API. '.$this->core->publicError;
				}
			}
			else{
				$e['response']='That Provider could not be found. '.$this->core->publicError;
			}
		}
		else{
			$e['response']='An internal error occurred assigning the provider connection. '.$this->core->publicError;
			$this->core->recordError('An error occurred in '.__METHOD__,array('sql'=>$sql,'args'=>$args,'query'=>$this->core->db->showQuery($sql,$args)));
		}
		return $e;
	}
	/**
	Get a list of Providers with orders that still require either new order, add, or remove processing.
	Looks up a database view that just contains matching Provider Ids.
	If no valid key is provided in the opts parameter, a list off all Providers is returned instead.  This option may not be in use.
	Helper function for this class only.  Not callable by instance or inheritance.
	@param array opts = array of options to narrow the search.  Currently only 'needs_processing', 'needs_removal' are valid.
	@return array, with keys of 'succcess'(int), 'response'(string), 'data'(array)
	*/
	private function _getProviders(array $opts):array{
		$r=array('success'=>0,'response'=>'','data'=>array());
		$sql='';
		$args=array();
		$needsProcessing=(isset($opts['needs_processing'])?(bool)$opts['needs_processing']:false);
		$needsRemoval=(isset($opts['needs_removal'])?(bool)$opts['needs_removal']:false);
		if($needsProcessing){
			$sql='SELECT * FROM unprocessed_providers_view';
		}
		else if($needsRemoval){
			$sql='SELECT * FROM unprocessed_removals_providers_view';
		}
		else{
			$sql='SELECT * FROM providers_tbl';
		}
		$this->core->db->prepare($sql);
		if($this->core->db->execute($args)!==false){
			$temp=$this->core->db->fetchAll();
			if(is_array($temp) && count($temp)>0){
				if($needsProcessing || $needsRemoval){
					foreach($temp as $t){
						$r['data'][]=$t['provider_id'];
					}
				}
				else{
					$r['data']=$temp;
				}
				$r['success']=1;
			}
			else{
				$r['response']='No Providers found.';
			}
		}
		else{
			$this->core->recordError('An error occurred in '.__METHOD__,array('sql'=>$sql,'args'=>$args,'query'=>$this->core->db->showQuery($sql,$args)));
			$r['response']='An internal error occurred. '.$this->core->publicError;
		}
		return $r;
	}
}
?>