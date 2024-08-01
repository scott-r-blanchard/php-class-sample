<?
/**
Interface to provide a normalized environment for many different Provider APIs with different processes.
@copyright: 2024 defend-id
@author: Scott Blanchard
*/
interface ProviderApiInterface{
	/**
	Public gateway function to various private data retrieval functions.
	@param string $what = type of data to retrieve. Valid options currently come from the end Provider API class.  If no provider set, this function defaults to a failure
	@param array $opts = optional array of args and settings for the data retrieval
	@return array, typically with keys of 'succcess'(int), 'response'(string), 'data'(array)
	*/
	public function get(string $what,array $opts):array;
	/**
	Public gateway to set various private class properties.
	@param string $what = name of class property to update.  Currently only accepts 'provider'
	@param array $opts = optional array of args and settings class member update
	@return bool
	*/
	public function set(string $what,array $opts):bool;
	/**
	Public gateway to run various private Provider API calls and other data processing functions.
	@param string $what = type of API function to perform.  Curent valid options are 'export-orders', 'process-removed-members'.  Anything else is just passed along to the PRovider API class to see if it had an action for that key
	@param array $opts = optional array of args and settings for processing function
	@return array, typically with keys of 'succcess'(int), 'response'(string), 'report'(array)
	*/
	public function run(string $what,array $opts):array;
	/**
	Public gateway to private Provider API start enrollment functions.
	@param string $type = type of API enrollment function to perform. Passed along to provider API class if Provider is set
	@param array $opts = array of args and settings for processing function
	@return array, with keys of 'succcess'(int), 'response'(string), 'api_response'(string)
	*/
	public function startEnrollment(string $type,array $opts):array;
	/**
	Public gateway to private Provider API cancel enrollment functions.
	@param string $type = type of API cancellation function to perform.
	@param array $opts = array of args and settings for processing function
	@return array, with keys of 'succcess'(int), 'response'(string), 'api_response'(string)
	*/
	public function cancelEnrollment(string $type,array $opts):array;
}
?>