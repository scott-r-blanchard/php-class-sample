# php-class-sample
Sample of recent PHP classes/interfaces
1. ProviderApiInterface.class.php - interface to help normailze various provider API interactions
2. ProviderApi.class.php - "gateway" class called directly by cron daemons that run the bulk API interactions.  Instantiates the API classes for various providers based on current order being processed
3. MISApi.class.php - direct API class for one of the providers.
4. UncompromizedApi.class.php - direct API class for one of the providers.
