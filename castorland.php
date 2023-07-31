#!/usr/bin/php5
<?php
	error_reporting(0);
	ini_set("display_errors", 0);

	define('DEBUG', false);
	define('PS_SHOP_PATH', 'http://some-site.ru');  // fake site
	define('PS_WS_AUTH_KEY', '11111111111111111111111111111111'); // fake key

	$tmpimg = sys_get_temp_dir().DIRECTORY_SEPARATOR.'$img$.jpg';

	echo "\n**********************\n Castorland parser v2\n**********************\n";

	require_once('PSWebServiceLibrary.php');
	
	if (!function_exists('curl_init'))
	    die ("\n* cURL required!\n");

	$loop = true;
	$page = 0;
	$PuzzlesArt = array ();
	$PuzzlesUrl = array ();
	$elements_cache = $box_cache = $size_cache = $age_cache = array ();

	

	try {
		$webService = new PrestaShopWebservice(PS_SHOP_PATH, PS_WS_AUTH_KEY, DEBUG);
		
		// новая xml-схема для характеристик
		$xml_feature = $webService->get(array('resource' => 'product_feature_values?schema=blank'));  
		
		// кеш по элементам
		$xml_props = $webService->get(array('resource' => 'product_feature_values', 'filter[id_feature]' => 9));
		preg_match_all('|id="(\d*)|',$xml_props->children()->asXML(),$props);
		$props=$props[1];
		foreach($props as $prop){
			$xml_prop = $webService->get(array('resource' => 'product_feature_values/'.$prop));                       
			$elements_cache[''.$xml_prop->children()->children()->value->language[0][0]]=$prop;
		}
		
		// кеш по размерам коробок
		$xml_props = $webService->get(array('resource' => 'product_feature_values', 'filter[id_feature]' => 10));
		preg_match_all('|id="(\d*)|',$xml_props->children()->asXML(),$props);
		$props=$props[1];
		foreach($props as $prop){
			$xml_prop = $webService->get(array('resource' => 'product_feature_values/'.$prop));                       
			$box_cache[''.$xml_prop->children()->children()->value->language[0][0]]=$prop;
		}
		
		// кеш по размерам пазлов
		$xml_props = $webService->get(array('resource' => 'product_feature_values', 'filter[id_feature]' => 11));
		preg_match_all('|id="(\d*)|',$xml_props->children()->asXML(),$props);
		$props=$props[1];
		foreach($props as $prop){
			$xml_prop = $webService->get(array('resource' => 'product_feature_values/'.$prop));                       
			$size_cache[''.$xml_prop->children()->children()->value->language[0][0]]=$prop;
		}

		// кеш по возрастным категориям
		$xml_props = $webService->get(array('resource' => 'product_feature_values', 'filter[id_feature]' => 12));
		preg_match_all('|id="(\d*)|',$xml_props->children()->asXML(),$props);
		$props=$props[1];
		foreach($props as $prop){
			$xml_prop = $webService->get(array('resource' => 'product_feature_values/'.$prop));                       
			$age_cache[''.$xml_prop->children()->children()->value->language[0][0]]=$prop;
		}

		// кеш издательствам
		$xml_props = $webService->get(array('resource' => 'product_feature_values', 'filter[id_feature]' => 7));
		preg_match_all('|id="(\d*)|',$xml_props->children()->asXML(),$props);
		$props=$props[1];
		foreach($props as $prop){
			$xml_prop = $webService->get(array('resource' => 'product_feature_values/'.$prop));                       
			$pub_cache[''.$xml_prop->children()->children()->value->language[0][0]]=$prop;
		}

		// кеш стран
		$xml_props = $webService->get(array('resource' => 'product_feature_values', 'filter[id_feature]' => 13));
		preg_match_all('|id="(\d*)|',$xml_props->children()->asXML(),$props);
		$props=$props[1];
		foreach($props as $prop){
			$xml_prop = $webService->get(array('resource' => 'product_feature_values/'.$prop));                       
			$region_cache[''.$xml_prop->children()->children()->value->language[0][0]]=$prop;
		}


	} catch (PrestaShopWebserviceException $e) {
		$trace = $e->getTrace();
		if ($trace[0]['args'][0] == 404) echo 'Bad ID';
		else if ($trace[0]['args'][0] == 401) echo 'Bad auth key';
		else echo 'Other error: '.$e->getMessage();
		slog ($e->getMessage());
	}

	print "\n";


	
	while ($loop) {

		print "\nPage=".($page+1);

		$curl = curl_init();
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($curl, CURLOPT_URL, 'https://www.castorland.pl/ru/puzzle?page='.$page);
		curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
		$headers=array(
			'Host:www.castorland.pl',
			'User-Agent:Mozilla/5.0 (Windows NT 6.1; WOW64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/33.0.1750.154 Safari/537.36 OPR/20.0.1387.91',
			'Accept-Encoding:gzip,deflate,lzma,sdch'
		);
		curl_setopt($curl,CURLOPT_HTTPHEADER, $headers);
		curl_setopt($curl,CURLOPT_ENCODING , "");

		curl_setopt($curl, CURLOPT_COOKIEFILE,sys_get_temp_dir().DIRECTORY_SEPARATOR);
		curl_setopt($curl, CURLOPT_COOKIEJAR, sys_get_temp_dir().DIRECTORY_SEPARATOR);

		curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 20);
		curl_setopt($curl, CURLOPT_TIMEOUT, 60);

		$Counter = 0;
		while (($PageCatalog = curl_exec($curl)) === false && $Counter++ < 10) {
		  print '.';
		}
		if($PageCatalog === FALSE) {
		    die(curl_error($curl));
		}

		preg_match_all('#<div class="node_container puzzle"><a href="/ru/puzzle/([a-zA-z]+-\w+)[-|\?|"]#', $PageCatalog, $_PuzzlesArt, PREG_PATTERN_ORDER);
		preg_match_all('#<div class="node_container puzzle"><a href="/ru/puzzle/(.+)(\?"?)#', $PageCatalog, $_PuzzlesUrl, PREG_PATTERN_ORDER);

		$PuzzlesArt = array_merge ($PuzzlesArt, $_PuzzlesArt[1]);
		$PuzzlesUrl = array_merge ($PuzzlesUrl, $_PuzzlesUrl[1]);
		if (count($_PuzzlesArt[1]) <> count($_PuzzlesUrl[1])) {
			print " Art: ".count($_PuzzlesArt[1])." Url: ".count($_PuzzlesUrl[1]);
			die ();
		} else {
			print " Found: ".count($_PuzzlesArt[1]);
		}

		preg_match('|<li class="pager-next last"><a href="\/ru\/puzzle\?page=(\d*)">.*</a></li>|', $PageCatalog, $p);
		if(isset($p[1])) {
			if($p[1] != $page) {
				$page = (int)$p[1];
			}
		
		} else {
			//break;
			$loop = false;
		}
	}

	print "\n-------------------->8------------------------";

 	foreach ($PuzzlesUrl as $key => $Url) {

		print "\n".$PuzzlesArt[$key].' ';
		$new = checknew ($PuzzlesArt[$key], 40);

		if ($new !== true && $new !== false) {
			print ' Found ';
			//continue;
		}
		if ($new === false) {
			print "\nFatal error";
			die ();
		}


		$PUrl = 'https://www.castorland.pl/ru/puzzle/'.$Url;
 		curl_setopt($curl, CURLOPT_URL, $PUrl);
		print " : ".$PUrl;
		$Counter = 0;
		while (($PagePuzzle = curl_exec($curl)) === false && $Counter++ < 10) {
		  print '.';
		}
		if($PagePuzzle === FALSE) {
		    die(curl_error($curl));
		}

		// images
		preg_match_all('#<li class="gallery-slide" id="slide-.+".+<a href="(.+)"#isU', $PagePuzzle, $_ImgUrls, PREG_PATTERN_ORDER);
		// ean
		preg_match('#<div class="field-label">EAN:&nbsp;</div><div class="field-items"><div class="field-item even">(\d+)\D*<\/div>#isU', $PagePuzzle, $_EAN);
		$_EAN[1] = trim ($_EAN[1]);
		if (strlen($_EAN[1]) > 13) {
			$_EAN[1] = substr($_EAN[1], 0, 13);
		}
		// elements
		preg_match('#(<div class="field-item even">Mini|<div class="elements"><span class="red-color">Пазлы|<div class="elements"><span class="red-color">Puzzle)[^\d]*(\d*)#is', $PagePuzzle, $_Elements);
		// name
		preg_match('#<div class="name">(.*)<\/div>#isU', $PagePuzzle, $_Name);
		$_Name[1] = strip_tags ($_Name[1]);
		$_Name[1] = json_decode (str_replace (array("\\u201e","&#039;"),'\"', json_encode($_Name[1])), 1);
		$_Name[1] = str_replace (';', ',', $_Name[1]);
		// text
		preg_match('#<div class="text">(.*)</div>#isU', $PagePuzzle, $_Text);
		$_Text[1] = strip_tags ($_Text[1]);
		// box size
		preg_match('#Размер коробки:(.*)</div></div>#isU', $PagePuzzle, $_Box);
		$_Box[1] = trim(strip_tags(str_replace('&nbsp;', ' ', $_Box[1])));
		$_Box[1] = str_replace ('&times;', 'x', $_Box[1]);
		// game size
		preg_match('#Размер головоломки:(.*)</span>#isU', $PagePuzzle, $_Game);
		$_Game[1] = trim(strip_tags(str_replace('&nbsp;', ' ', $_Game[1])));
		$_Game[1] = str_replace ('&times;', 'x', $_Game[1]);
		// age
		preg_match('#title="возрастная группа(.*)"#isU', $PagePuzzle, $_Age);
		$_Age[1] = trim(strip_tags(str_replace('&nbsp;', ' ', $_Age[1])));


		if ($new === true) {
			try {
				$xml_product = $webService->get(array('url' => PS_SHOP_PATH.'/api/products?schema=blank'));  /// новый
			} catch (PrestaShopWebserviceException $e) {
				$trace = $e->getTrace();
				if ($trace[0]['args'][0] == 404) echo 'Bad ID';
				else if ($trace[0]['args'][0] == 401) echo 'Bad auth key';
				else echo 'Other error<br />'.$e->getMessage();
				slog ($e->getMessage());
				die ('Schema product');
			} 
		} else {

			// exists
			try {
				$xml_product = $webService->get(array('resource' => 'products', 'id' => $new));  /// загрузка на редактирование
			} catch (PrestaShopWebserviceException $e) {
				$trace = $e->getTrace();
				if ($trace[0]['args'][0] == 404) echo 'Bad ID';
				else if ($trace[0]['args'][0] == 401) echo 'Bad auth key';
				else echo 'Other error<br />'.$e->getMessage();
				slog ($e->getMessage());
				die ('Load product');
			}
		}
		$resources_product = $xml_product->children()->children();

		unset ($resources_product->manufacturer_name);
		unset ($resources_product->quantity);

		$resources_product->name->language[0][0] =  $_Name[1];
		$resources_product->description->language[0][0] =  $_Text[1];
		$resources_product->active = 0;
		$resources_product->available_for_order = 0;
		$resources_product->show_price = 1;
		$resources_product->weight = 200;
		$resources_product->reference = $PuzzlesArt[$key];
		$resources_product->ean13 = $_EAN[1];
		$resources_product->id_manufacturer = 40;
		$resources_product->id_supplier = 2;
		$resources_product->supplier_reference = $PuzzlesArt[$key];
		$resources_product->out_of_stock = 2;		//Если товара нет на складе, действие по умолчанию

		if ($new === true) {
			$resources_product->price = 0;			// пока без цены
			$resources_product->wholesale_price = 0;
		}
		//print_r ($resources_product);

		$resources_product->id_category_default = 63;
		//Если не указать категорию, товар не будет виден в админке, это важно
		$resources_product->associations->categories->category[0]->id = 63;
		print ' f';
		addfuture ($webService, $resources_product, $xml_feature, 9, $_Elements[2], 0, $elements_cache);	// элементов
		print 'e';
		addfuture ($webService, $resources_product, $xml_feature, 10, $_Box[1], 0, $box_cache);			// размер коробки
		print 'b';
		addfuture ($webService, $resources_product, $xml_feature, 11, $_Game[1], 0, $size_cache);		// размер
		print 's';
		if (strlen ($_Age[1]))
			addfuture ($webService, $resources_product, $xml_feature, 12, $_Age[1], 0, $age_cache);			// возраст
		else
			print '-';
		print 'a';
		addfuture ($webService, $resources_product, $xml_feature, 7, 'Castorland', 0, $pub_cache);		// издательство
		print 'p';
		addfuture ($webService, $resources_product, $xml_feature, 13, 'Польша', 0, $region_cache);		// страна
		print 'r';

		//print_r ($resources_product);
		//die();

		if ($new === true) {
			try {
				$created_product = $webService->add(array('resource' => 'products', 'postXml' => $xml_product->asXML()));  // новый
			} catch (PrestaShopWebserviceException $e) {
				$trace = $e->getTrace();
				if ($trace[0]['args'][0] == 404) echo 'Bad ID';
				else if ($trace[0]['args'][0] == 401) echo 'Bad auth key';
				else echo 'Other error<br />'.$e->getMessage();
				slog ($e->getMessage());

				$lf = fopen ('castor.txt', 'a');
				fputs ($lf, 'n='.$_Name[1]."\n");
				fputs ($lf, 'a='.$_Age[1]."\n");
				fputs ($lf, 't='.$_Text[1]."\n");
				fputs ($lf, 'e='.$_Elements[2]."\n");
				fputs ($lf, 'e='.$_EAN[1]."\n");
				fputs ($lf, 'b='.$_Box[1]."\n");
				fputs ($lf, 'g='.$_Game[1]."\n");
				fputs ($lf, 'i='.implode($_ImgUrls[1], "\n"));
				fputs ($lf, "\n--------------------------\n");
				fclose ($lf);
				die ('Create product');
			}
			print "\t*CREATED ";

		} else {
			try {
				$created_product = $webService->edit(array('resource' => 'products', 'id' => $new, 'putXml' => $xml_product->asXML()) );  // редактирование
			} catch (PrestaShopWebserviceException $e) {
				$trace = $e->getTrace();
				if ($trace[0]['args'][0] == 404) echo 'Bad ID';
				else if ($trace[0]['args'][0] == 401) echo 'Bad auth key';
				else echo 'Other error<br />'.$e->getMessage();
				slog ($e->getMessage());
				die ('Edit product');
			}
			print "\t*UPDATED ";

		}
		if ($new === true) {
			$url_img_api = PS_SHOP_PATH.'/api/images/products/'.(int)$created_product->product->id;
			foreach ($_ImgUrls[1] as $urlimg) {
				echo " | img=".$urlimg.'..';
				$cnerr = 0;
				while ($cnerr < 10 and !($cpres=copy ($urlimg, $tmpimg))) { 
					$cnerr++; 
					echo '.'; 
				}
				if (!$cpres) { 
					echo "error img, no-skip "; 
					slog('img-'.$s_id); 
				} else {
					//$url = PS_SHOP_PATH.'/api/images/products/'.(int)$created_product->product->id;
					//$image_path = 'g:\perm-books\img.png';
					$ch = curl_init();
					curl_setopt($ch, CURLOPT_URL, $url_img_api);
					curl_setopt($ch, CURLOPT_POST, true);
					curl_setopt($ch, CURLOPT_USERPWD, PS_WS_AUTH_KEY.':');
					curl_setopt($ch, CURLOPT_POSTFIELDS, array('image' => '@'.$tmpimg));
					curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
					$result = curl_exec($ch);
					curl_close($ch);
					print " *IMG_UPLOADED ";

				}	// else
			}	// foreach
		}	// if

		/*$lf = fopen ('castor.txt', 'a');
		fputs ($lf, 'n='.$_Name[1]."\n");
		fputs ($lf, 'a='.$_Age[1]."\n");
		fputs ($lf, 't='.$_Text[1]."\n");
		fputs ($lf, 'e='.$_Elements[1]."\n");
		fputs ($lf, 'e='.$_EAN[1]."\n");
		fputs ($lf, 'b='.$_Box[1]."\n");
		fputs ($lf, 'g='.$_Game[1]."\n");
		fputs ($lf, 'i='.implode($_ImgUrls[1], "\n"));
		fputs ($lf, "\n--------------------------\n");

		fclose ($lf);*/


		//print_r($_ImgUrls[1]);

	}
	            

	function slog ($strlog) {
		$lf = fopen ('errors.txt', 'a');
		fputs ($lf, $strlog."\n");
		fclose ($lf);
	}

	function addfuture (&$webService, &$resources_product, &$xml_feature, $id_feature, $value, $custom=0, &$cache) {
			if (!$id_feature)
				return;
			$value = trim ($value);
			if (!strlen ($value)) {
				return;
			}
			if(isset($cache[$value]))
				$created_feature_id = $cache[$value];
			else {

				$xml_feature->children()->children()->id_feature = $id_feature;
				$xml_feature->children()->children()->custom = $custom;
				$xml_feature->children()->children()->value->language[0][0]  = $value;
				try {
					$created_feature_id = $webService->add(array('resource' => 'product_feature_values', 'postXml' => $xml_feature->asXML()))->product_feature_value->id;
					if ($cache !== false)
						$cache[$value] = $created_feature_id;
				} catch (PrestaShopWebserviceException $e) {
					$trace = $e->getTrace();
					if ($trace[0]['args'][0] == 404) echo 'Bad ID';
					else if ($trace[0]['args'][0] == 401) echo 'Bad auth key';
					else echo 'Other error<br />'.$e->getMessage();
					slog ($id_feature.' '.$value.' '.$e->getMessage().' ');
					die ();
					//return false;
				}
			}
			//print "((((((((((((((((((("; print_r ($resources_product->associations->product_features); print ")))))))))))))))))))))))";
			$_i = 0;
			foreach ($resources_product->associations->product_features->product_feature as $_t) {
				if ($_t->id == $id_feature) {
					if ($_t->id_feature_value == $created_feature_id) {
						print '+';
						return;
					}
					unset ($resources_product->associations->product_features->product_feature[$_i]);
				}
				//print "--".$_i."**".$_t->id."###".$_t->id_feature_value;
			$_i++;
			}


			$newFeature = $resources_product->associations->product_features->addChild('product_feature');
			$newFeature->addChild('id', $id_feature);
			$newFeature->addChild('id_feature_value', $created_feature_id);
			print '*';
			
	}
	function checknew ($s_id, $id_manufacturer) {
		global $webService;
		/// проверка, есть ли в базе продукт с таким айди поставщика
		try {
			$opt = array(
					'resource' => 'products',
					//'display'  => 'full',
					'filter[reference]' => $s_id,  
					'filter[id_manufacturer]' => $id_manufacturer,   //120018
					//'filter[product_supplier_reference]' => $s_id,
			);
			$id_prod = (int)$webService->get($opt)->products->product->attributes(); //->attributes();

			print $id_prod;

			if ($id_prod) {
				return $id_prod;
			}
			else {
				return true;
			}
			
		} catch (PrestaShopWebserviceException $e) {
			$trace = $e->getTrace();
			if ($trace[0]['args'][0] == 404) echo 'Bad ID';
			else if ($trace[0]['args'][0] == 401) echo 'Bad auth key';
			else echo 'Other error<br />'.$e->getMessage();
			slog ($e->getMessage());
			return false;
		}
	}		
	
?>