#!/usr/bin/php5
<?PHP
	error_reporting(0);
	ini_set("display_errors", 0);
	mb_internal_encoding("UTF-8");

	define('DEBUG', false);
	define('PS_SHOP_PATH', 'http://some-site.ru'); // fake site
	define('PS_WS_AUTH_KEY', '11111111111111111111111111111111'); // fake key
	define('price_File', 'castor.csv');
	if (!function_exists('curl_init'))
	    die ("\n*E cURL required!\n");

	require_once('PSWebServiceLibraryPatched.php');
	$fp = fopen (price_File, 'r') or die ('*R Error open '.price_File);

	try {
		$webService = new PrestaShopWebservice(PS_SHOP_PATH, PS_WS_AUTH_KEY, DEBUG);
	} catch (PrestaShopWebserviceException $e) {
		$trace = $e->getTrace();
		if ($trace[0]['args'][0] == 404) echo 'Bad ID';
		else if ($trace[0]['args'][0] == 401) echo 'Bad auth key';
		else echo 'Other error: '.$e->getMessage();
		die ();
		//slog ($e->getMessage());
	}

	$_cnt = 1;
	$errortrigger = 0;

	while ($data = fgetcsv($fp, 16384, ';')) {

		startloop:
		if ($errortrigger >= 10) {
			$errortrigger = 0;
			continue;
		}

		$_art = $data[0];
		$_price = $data[1];

		print "\n".($_cnt++).": $_art - $_price";


		try {
			$opt = array(
					'resource' => 'products',
					//'display'  => 'full',
					'filter[reference]' => '%['.$_art.']',   //120018
					'filter[id_manufacturer]' => 40,   //120018
					//'filter[product_supplier_reference]' => $s_id,
			);
			$id_prod = (int)$webService->get($opt)->products->product->attributes(); //->attributes();
			
			//print_r ($id_prod);
			//die ('----------'); //.$id_prod['id']);
			
			if ($id_prod) {
				print ' FOUND['.$id_prod.'] ';
				$xml_product = $webService->get(array('resource' => 'products', 'id' => $id_prod));  /// загрузка на редактирование
				$resources_product = $xml_product->children()->children();
				unset ($resources_product->manufacturer_name);
				unset ($resources_product->quantity);
				$resources_product->available_for_order = 1;
				$resources_product->price = (int)($_price*1.55);
				$resources_product->wholesale_price = $_price;
				$resources_product->active = 1;
				$resources_product->on_sale = 1;
				$created_product = $webService->edit(array('resource' => 'products', 'id' => $id_prod, 'putXml' => $xml_product->asXML()) );  // редактирование
				print ' OK';
			}
			
		} catch (PrestaShopWebserviceException $e) {
			$trace = $e->getTrace();
			if ($trace[0]['args'][0] == 404) echo 'Bad ID';
			else if ($trace[0]['args'][0] == 401) echo 'Bad auth key';
			else echo 'Other error<br />'.$e->getMessage();
			//die ($e->getMessage());
			print ' x['.$e->getMessage().']';
			goto startloop;
		}



	}