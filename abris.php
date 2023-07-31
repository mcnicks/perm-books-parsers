#!/usr/bin/php5
<?PHP
	error_reporting(0);
	ini_set("display_errors", 0);
	mb_internal_encoding("UTF-8");

	define('DEBUG', true);
	define('PS_SHOP_PATH', 'http://some-books.ru'); // fake path
	define('PS_WS_AUTH_KEY', 'KPZUE111234511122233311122233344'); // fake key

	$class_id = array (	// категории по классам с id
		1  => 12,
		2  => 13,
		3  => 14,
		4  => 15,
		5  => 16,
		6  => 17,
		7  => 18,
		8  => 19,
		9  => 20,
		10 => 21,
		11 => 22
	);


	/// настройки столбцов прайс-листа
	define('price_File', 'Price_all.csv');
	define('price_ID', 0);
	define('price_Name', 2);
	define('price_Publisher', 3);
	define('price_ISBN', 4);
	define('price_Year', 5);
	define('price_Pages', 6);
	define('price_Pereplet', 7);
	define('price_Standart', 8);
	define('price_FP', 11);
	define('price_Complect', 12);
	define('price_Price', 14);

	$cnt_new = 0;
	$cnt_old = 0;
	$cnt_img = 0;
	$cnt_err = 0;
	$cnt_scr = 0;

	$tmpimg = sys_get_temp_dir().DIRECTORY_SEPARATOR.'$img$.jpg';
	$currentpositionfile = sys_get_temp_dir().DIRECTORY_SEPARATOR.'permbooks1.pos';
	//$currentpositionfile = '.'.DIRECTORY_SEPARATOR.'permbooks1.pos';
	
	echo "\n*****************\n Abris parser v2\n*****************\n";
	
	if (!function_exists('curl_init'))
	    die ("\n*E cURL required!\n");

	if (file_exists ($currentpositionfile)) {
		$currentposition = (int)file_get_contents ($currentpositionfile);
		echo "\n*R Interrupted job. Resuming from ID=".$currentposition."\n";
	} else {
		$currentposition = 0;
		echo "\n*R ".$currentpositionfile." doesn't exist. New job.\n";
	}
	require_once('PSWebServiceLibraryPatched.php');
	$fp = fopen (price_File, 'r') or die ('*R Error open '.price_File);

	try {
		$webService = new PrestaShopWebservice(PS_SHOP_PATH, PS_WS_AUTH_KEY, DEBUG);
		
		// новая xml-схема для характеристик
		$xml_feature = $webService->get(array('resource' => 'product_feature_values?schema=blank'));  
		
		// кеш по годам
		$xml_props = $webService->get(array('resource' => 'product_feature_values', 'filter[id_feature]' => 4));
		preg_match_all('|id="(\d*)|',$xml_props->children()->asXML(),$props);
		$props=$props[1];
		foreach($props as $prop){
			$xml_prop = $webService->get(array('resource' => 'product_feature_values/'.$prop));                       
			$years_cache[''.$xml_prop->children()->children()->value->language[0][0]]=$prop;
		}
		
		// кеш по страницам
		$xml_props = $webService->get(array('resource' => 'product_feature_values', 'filter[id_feature]' => 5));
		preg_match_all('|id="(\d*)|',$xml_props->children()->asXML(),$props);
		$props=$props[1];
		foreach($props as $prop){
			$xml_prop = $webService->get(array('resource' => 'product_feature_values/'.$prop));                       
			$pages_cache[''.$xml_prop->children()->children()->value->language[0][0]]=$prop;
		}
		
		// кеш по стандартам
		$xml_props = $webService->get(array('resource' => 'product_feature_values', 'filter[id_feature]' => 6));
		preg_match_all('|id="(\d*)|',$xml_props->children()->asXML(),$props);
		$props=$props[1];
		foreach($props as $prop){
			$xml_prop = $webService->get(array('resource' => 'product_feature_values/'.$prop));                       
			$standard_cache[''.$xml_prop->children()->children()->value->language[0][0]]=$prop;
		}

		// кеш по издательствам
		$xml_props = $webService->get(array('resource' => 'product_feature_values', 'filter[id_feature]' => 7));
		preg_match_all('|id="(\d*)|',$xml_props->children()->asXML(),$props);
		$props=$props[1];
		foreach($props as $prop){
			$xml_prop = $webService->get(array('resource' => 'product_feature_values/'.$prop));                       
			$pub_cache[''.$xml_prop->children()->children()->value->language[0][0]]=$prop;
		}

		// кеш по федеральным перечням
		$xml_props = $webService->get(array('resource' => 'product_feature_values', 'filter[id_feature]' => 8));
		preg_match_all('|id="(\d*)|',$xml_props->children()->asXML(),$props);
		$props=$props[1];
		foreach($props as $prop){
			$xml_prop = $webService->get(array('resource' => 'product_feature_values/'.$prop));                       
			$fp_cache[''.$xml_prop->children()->children()->value->language[0][0]]=$prop;
		}

		// кеш по возрастным группам
		$xml_props = $webService->get(array('resource' => 'product_feature_values', 'filter[id_feature]' => 12));
		preg_match_all('|id="(\d*)|',$xml_props->children()->asXML(),$props);
		$props=$props[1];
		foreach($props as $prop){
			$xml_prop = $webService->get(array('resource' => 'product_feature_values/'.$prop));                       
			$age_cache[''.$xml_prop->children()->children()->value->language[0][0]]=$prop;
		}

	} catch (PrestaShopWebserviceException $e) {
		$trace = $e->getTrace();
		if ($trace[0]['args'][0] == 404) echo 'Bad ID';
		else if ($trace[0]['args'][0] == 401) echo 'Bad auth key';
		else echo 'Other error: '.$e->getMessage();
		slog ($e->getMessage());
	}

	print "\n";
	$errortrigger = 0;
	while ($data = fgetcsv($fp, 16384, ';')) {
		
		startloop:					// очень стыдно

		if ($errortrigger >= 10) {
			$errortrigger = 0;
			continue;
		}

		$needimage = false;
		$s_id = (int)$data[price_ID];
		if (!$s_id) continue;

		//// восстановление обработки с последней позиции
		if ($currentposition > 0) {
			if ($currentposition <> $s_id) continue;
			else $currentposition = 0;
		}
		//// сохраняем текущую позицию на случай возобновления
		$currentpositionfilehandler = fopen ($currentpositionfile, 'w');
		fputs ($currentpositionfilehandler, $s_id);
		fclose ($currentpositionfilehandler);

		$howmuch = (float)str_replace(',', '.', $data[price_Price]);
		$_complect = chop($data[price_Complect]);
		$book_name = iconv("WINDOWS-1251", "UTF-8", $data[price_Name]);
		$book_pub = iconv("WINDOWS-1251", "UTF-8", chop($data[price_Publisher]));
		$book_fp = iconv("WINDOWS-1251", "UTF-8", chop($data[price_FP]));
		$book_pereplet = iconv("WINDOWS-1251", "UTF-8", $data[price_Pereplet]);
		$book_isbn = (string)chop($data[price_ISBN]);
		if (!strlen($book_isbn))
			$book_isbn = '-';
		
		$ean13 = preg_replace('/[^0-9,]/', '', $book_isbn);
			
		$val_year = (int)chop($data[price_Year]);
		$val_pages = (int)chop($data[price_Pages]);
		$val_standard = (int)chop($data[price_Standart]);
			
		$_pub_per = calc_p ($book_pub);
		if ($_pub_per === false) continue;
		$val_price = calc_price ($_pub_per['percent'], $howmuch);

		$thousand = (int)($s_id/1000);
		echo "\nID=".$s_id.": ";
			
		if (strlen($_complect))
			print " {C} ";

			

		$val_grp = grp ($book_name, $book_pub );
		echo ' G='.$val_grp.' ';

		$book_name = str_replace('"', '\'', $book_name);
		$book_name = str_replace('\'', '\'', $book_name);
		$book_name = str_replace('`', '\'', $book_name);
		$book_name = str_replace(';', ',', $book_name);
		$book_name = str_replace('=', '-', $book_name);


		/// проверка, есть ли в базе продукт с таким айди поставщика
		try {
			$opt = array(
					'resource' => 'products',
					//'display'  => 'full',
					'filter[reference]' => $s_id,   //120018
					'filter[id_supplier]' => 1,   //120018
					//'filter[product_supplier_reference]' => $s_id,
			);
			$id_prod = (int)$webService->get($opt)->products->product->attributes(); //->attributes();
			
			//print_r ($id_prod);
			//die ('----------'); //.$id_prod['id']);
			
			if ($id_prod) {
				print ' FOUND['.$id_prod.'] ';
			}
			
		} catch (PrestaShopWebserviceException $e) {
			$trace = $e->getTrace();
			if ($trace[0]['args'][0] == 404) echo 'Bad ID';
			else if ($trace[0]['args'][0] == 401) echo 'Bad auth key';
			else echo 'Other error<br />'.$e->getMessage();
			slog ($e->getMessage());
			print "\n*E Search record TRY: ".$errortrigger++;
			goto startloop;
		}
			
		if (!$id_prod) { 
			print '*NEW ' ;
			$new = true;
			$cnt_new ++;
		} else {
			$new = false;
			$cnt_old ++;
		}
		if ($new) 
			try {
				$xml_product = $webService->get(array('url' => PS_SHOP_PATH.'/api/products?schema=blank'));  /// новый
			} catch (PrestaShopWebserviceException $e) {
				$trace = $e->getTrace();
				if ($trace[0]['args'][0] == 404) echo 'Bad ID';
				else if ($trace[0]['args'][0] == 401) echo 'Bad auth key';
				else echo 'Other error<br />'.$e->getMessage();
				slog ($e->getMessage());
				print "\n*E Schema product TRY: ".$errortrigger++;
				goto startloop;

		} else {
			try {
				$xml_product = $webService->get(array('resource' => 'products', 'id' => $id_prod));  /// загрузка на редактирование
			} catch (PrestaShopWebserviceException $e) {
				$trace = $e->getTrace();
				if ($trace[0]['args'][0] == 404) echo 'Bad ID';
				else if ($trace[0]['args'][0] == 401) echo 'Bad auth key';
				else echo 'Other error<br />'.$e->getMessage();
				slog ($e->getMessage());
				print "\n*E Load product TRY: ".$errortrigger++;
				goto startloop;

			}
		}
		$resources_product = $xml_product->children()->children();

		/// обновить цену в любом случае
		$resources_product->price = $val_price['rozn'];
		$resources_product->wholesale_price = $val_price['opt'];

		// категории
		$resources_product->id_category_default = $val_grp;
		//$resources_product->associations->categories->category[0]->id = $val_grp;

		//////// **********************************************
		unset ($resources_product->associations->categories);
		$resources_product->associations->categories->category[0]->id = $val_grp;

		// массив категорий по классам
		$_i = 1;
		$str_ages = '';
		$arcl = explodeclasses ($book_name, $str_ages);
		foreach ($arcl as $_class) {
			$resources_product->associations->categories->category[$_i++]->id = $class_id[$_class];
		}
		print ' CLASS['.implode(',', $arcl).'] ';
		if (strlen ($str_ages))
			addfeature ($webService, $resources_product, $xml_feature, 12, $str_ages, 0, $age_cache);	// классы

		///////// ********************************************

		$yearfeat = ($val_year>0)?$val_year:'не указан';
		addfeature ($webService, $resources_product, $xml_feature, 4, $yearfeat, 0, $years_cache);	// год издания

		if (checkege ($book_name, $val_year)) {									// отдельная категория для ЕГЭ
			$resources_product->associations->categories->category[$_i++]->id = 65;
			print ' EGE[2016] ';	
		}

		// если цена не посчиталась, делаем недоступным для заказа
		if ($val_price['rozn'] > 0) {
			$resources_product->available_for_order = 1;
		} else {
			$resources_product->available_for_order = 0;
		}

		/////   акция-распродажа
		$resources_product->on_sale = 0;

		unset ($resources_product->manufacturer_name);
		unset ($resources_product->quantity);

		// описание с сайта    [1]

		// перенести в [1]
		// описание и зарактеристики - только для нового
		if ($new) {
		// до сюда

			$urltext = 'http://textbook.ru/catalog/view/'.$s_id;
			echo "html=".$urltext.'..';
			$cnerr = 0;
			while ($cnerr < 10 and !($__fcontent = file ($urltext))) { $cnerr++; echo '.'; }
			if (!$__fcontent) { 
				echo "X\n*E HTML _skip_ "; 
				slog('html-'.$s_id); 
				$cnt_err ++; 
				continue; 
			}

			$html_content = implode ('', $__fcontent);
			echo strlen ($html_content).' OK';
			$val_author = str_replace(';', ',', extract_data ($html_content, 'itemgroup="author">', '</a>'));
			$val_weight = (float)str_replace(';', ',', extract_data ($html_content, '<div><span>Вес:</span>', '</div>'));
			if ($val_weight == 0) {		// если вес не определился
				$val_weight = 100;
			}
			$val_descr = str_replace(';', ',', trim(strip_tags(extract_data ($html_content, '<h1>Аннотация</h1>', '</div>'))));
			
			if (strlen($_complect)) $val_descr = "Обратите внимание: продается только в комплекте!\n<br>".$val_descr;
			
			print ' DESCR='.strlen($val_descr);
					
			$resources_product->name->language[0][0] =  (string)$book_name;
			$resources_product->description->language[0][0] =  (string)$val_descr;
			$resources_product->description_short->language[0][0] =  smarty_modifier_mb_truncate((string)$val_descr, 360);
			$resources_product->active = 1;
			//$resources_product->available_for_order = 1;
			$resources_product->show_price = 1;
			$resources_product->weight = $val_weight;
			$resources_product->reference = $s_id;
			$resources_product->ean13 = $ean13;
			$resources_product->id_manufacturer = $_pub_per['pub'];
			$resources_product->id_supplier = 1;
			$resources_product->supplier_reference = $s_id;
			


			/////////////////////////////////////////
			// сюда вставлять [1], если понадобится
			/////////////////////////////////////////

			echo " ";
			// ФП
			if (strlen($book_fp)) {
				try {
					if(isset($fp_cache[$book_fp]))
						$prop_id=$fp_cache[$book_fp];
					else {
						$xml_feature->children()->children()->id_feature = 8;
						$xml_feature->children()->children()->custom = 0;
						$xml_feature->children()->children()->value->language[0][0] = $book_fp;
						$prop_id = $webService->add(array('resource' => 'product_feature_values', 'postXml' => $xml_feature->asXML()))->product_feature_value->id;
						$fp_cache[$book_fp]=$prop_id;
					}
					$newFeature = $resources_product->associations->product_features->addChild('product_feature');
					$newFeature->addChild('id', 8); 
					$newFeature->addChild('id_feature_value', $prop_id);
					echo "F";
				} catch (PrestaShopWebserviceException $e) {
					$trace = $e->getTrace();
					if ($trace[0]['args'][0] == 404) echo 'Bad ID';
					else if ($trace[0]['args'][0] == 401) echo 'Bad auth key';
					else echo 'Other error<br />'.$e->getMessage();
					slog ($e->getMessage());
					echo "\n*E Error FP\n";
				}
			}

			// автор
			$xml_feature->children()->children()->id_feature = 1;
			$xml_feature->children()->children()->custom = 1;
			$xml_feature->children()->children()->value->language[0][0]  = $val_author;
			try {
				$created_feature_id = $webService->add(array('resource' => 'product_feature_values', 'postXml' => $xml_feature->asXML()))->product_feature_value->id;
				$newFeature = $resources_product->associations->product_features->addChild('product_feature');
				$newFeature->addChild('id', 1);
				$newFeature->addChild('id_feature_value', $created_feature_id);
				echo "A";

			} catch (PrestaShopWebserviceException $e) {
				$trace = $e->getTrace();
				if ($trace[0]['args'][0] == 404) echo 'Bad ID';
				else if ($trace[0]['args'][0] == 401) echo 'Bad auth key';
				else echo 'Other error<br />'.$e->getMessage();
				slog ($e->getMessage());
				echo "\n*E Error Author\n";
			}

			// издательство
			try {
				if(isset($pub_cache[$book_pub]))
					$prop_id=$pub_cache[$book_pub];
				else {
					$xml_feature->children()->children()->id_feature = 7;
					$xml_feature->children()->children()->custom = 0;
					$xml_feature->children()->children()->value->language[0][0] = $book_pub;

					$prop_id = $webService->add(array('resource' => 'product_feature_values', 'postXml' => $xml_feature->asXML()))->product_feature_value->id;
					$pub_cache[$book_pub]=$prop_id;
					echo "P";
					
				}
				$newFeature = $resources_product->associations->product_features->addChild('product_feature');
				$newFeature->addChild('id', 7); 
				$newFeature->addChild('id_feature_value', $prop_id);
			} catch (PrestaShopWebserviceException $e) {
				$trace = $e->getTrace();
				if ($trace[0]['args'][0] == 404) echo 'Bad ID';
				else if ($trace[0]['args'][0] == 401) echo 'Bad auth key';
				else echo 'Other error<br />'.$e->getMessage();
				slog ($e->getMessage());
				echo "\n*E Error publisher\n";
			}
			
						
			// переплет
			$newFeature = $resources_product->associations->product_features->addChild('product_feature');
			$newFeature->addChild('id', 3);
			$newFeature->addChild('id_feature_value', pereplet_id($book_pereplet));
			echo "O";
			
			// isbn
			$xml_feature->children()->children()->id_feature = 2;
			$xml_feature->children()->children()->custom = 1;
			$xml_feature->children()->children()->value->language[0][0]  = $book_isbn;

			try {
				$created_feature_id = $webService->add(array('resource' => 'product_feature_values', 'postXml' => $xml_feature->asXML()))->product_feature_value->id;
				$newFeature = $resources_product->associations->product_features->addChild('product_feature');
				$newFeature->addChild('id', 2); 
				$newFeature->addChild('id_feature_value', $created_feature_id); // Это реальный id значения фичи id 7
			} catch (PrestaShopWebserviceException $e) {
				$trace = $e->getTrace();
				if ($trace[0]['args'][0] == 404) echo 'Bad ID';
				else if ($trace[0]['args'][0] == 401) echo 'Bad auth key';
				else echo 'Other error<br />'.$e->getMessage();
				slog ($e->getMessage());
				echo "\n*E Error ISBN\n";
			}

			// год
			//try {
			//	if(isset($years_cache[$val_year]))
			//		$prop_id=$years_cache[$val_year];
			//	else {
			//		$xml_feature->children()->children()->id_feature = 4;
			//		$xml_feature->children()->children()->custom = 0;
			//		$xml_feature->children()->children()->value->language[0][0] = $val_year;
			//		$prop_id = $webService->add(array('resource' => 'product_feature_values', 'postXml' => $xml_feature->asXML()))->product_feature_value->id;
			//		$years_cache[$val_year]=$prop_id;
			//	}
			//	$newFeature = $resources_product->associations->product_features->addChild('product_feature');
			//	$newFeature->addChild('id', 4); 
			//	$newFeature->addChild('id_feature_value', $prop_id);
			//	echo "Y";
			//} catch (PrestaShopWebserviceException $e) {
			//	$trace = $e->getTrace();
			//	if ($trace[0]['args'][0] == 404) echo 'Bad ID';
			//	else if ($trace[0]['args'][0] == 401) echo 'Bad auth key';
			//	else echo 'Other error<br />'.$e->getMessage();
			//	slog ($e->getMessage());
			//	echo "\n*E Error year\n";
			//}
			
			
			// страниц
			if ((int)$val_pages) {
				try {
					if(isset($pages_cache[$val_pages]))
						$prop_id=$pages_cache[$val_pages];
					else {
						$xml_feature->children()->children()->id_feature = 5;
						$xml_feature->children()->children()->custom = 1;
						$xml_feature->children()->children()->value->language[0][0] = $val_pages;
						$prop_id = $webService->add(array('resource' => 'product_feature_values', 'postXml' => $xml_feature->asXML()))->product_feature_value->id;
						$pages_cache[$val_pages]=$prop_id;
					}
					$newFeature = $resources_product->associations->product_features->addChild('product_feature');
					$newFeature->addChild('id', 5); 
					$newFeature->addChild('id_feature_value', $prop_id);
					echo "V";
				} catch (PrestaShopWebserviceException $e) {
					$trace = $e->getTrace();
					if ($trace[0]['args'][0] == 404) echo 'Bad ID';
					else if ($trace[0]['args'][0] == 401) echo 'Bad auth key';
					else echo 'Other error<br />'.$e->getMessage();
					slog ($e->getMessage());
					echo $val_pages;
					echo "\n*E Error pages";
				}
			}
		
			// стандарт
			if ((int)$val_standard) {
				try {
					if(isset($standard_cache[$val_standard]))
						$prop_id=$standard_cache[$val_standard];
					else {
						$xml_feature->children()->children()->id_feature = 6;
						$xml_feature->children()->children()->custom = 1;
						$xml_feature->children()->children()->value->language[0][0] = $val_standard;
						$prop_id = $webService->add(array('resource' => 'product_feature_values', 'postXml' => $xml_feature->asXML()))->product_feature_value->id;
						$standard_cache[$val_standard]=$prop_id;
					}
					$newFeature = $resources_product->associations->product_features->addChild('product_feature');
					$newFeature->addChild('id', 6); 
					$newFeature->addChild('id_feature_value', $prop_id);
					echo "S";
				} catch (PrestaShopWebserviceException $e) {
					$trace = $e->getTrace();
					if ($trace[0]['args'][0] == 404) echo 'Bad ID';
					else if ($trace[0]['args'][0] == 401) echo 'Bad auth key';
					else echo 'Other error<br />'.$e->getMessage();
					slog ($e->getMessage());
					echo "\n*E Error standard\n";
				}
			}
			
			$resources_product->out_of_stock = 2;//Если товара нет на складе, действие по умолчанию
			//Если не указать категорию, товар не будет виден в админке, это важно
			$resources_product->associations->categories->category[0]->id = $val_grp;

			try {
				$created_product = $webService->add(array('resource' => 'products', 'postXml' => $xml_product->asXML()));  // новый
			} catch (PrestaShopWebserviceException $e) {
				$trace = $e->getTrace();
				if ($trace[0]['args'][0] == 404) echo 'Bad ID';
				else if ($trace[0]['args'][0] == 401) echo 'Bad auth key';
				else echo 'Other error<br />'.$e->getMessage();
				slog ($e->getMessage());
				print "\n*E Create product TRY: ".$errortrigger++;
				goto startloop;
			}
			print "  *CREATED";
			print ' [New ID='.$created_product->product->id.']';
			
			$needimage = true;
			$url_img_api = PS_SHOP_PATH.'/api/images/products/'.(int)$created_product->product->id;

		} else {		// если существующая запись
			try {
				$created_product = $webService->edit(array('resource' => 'products', 'id' => $id_prod, 'putXml' => $xml_product->asXML()) );  // редактирование
			} catch (PrestaShopWebserviceException $e) {
				$trace = $e->getTrace();
				if ($trace[0]['args'][0] == 404) echo 'Bad ID';
				else if ($trace[0]['args'][0] == 401) echo 'Bad auth key';
				else echo 'Other error<br />'.$e->getMessage();
				slog ($e->getMessage());
				print "\n*E Edit product TRY: ".$errortrigger++;
				goto startloop;

			}
			print "\t*UPDATED";
			
			/// проверка, есть ли в базе картинка
			try {
				$opt = array(
					'resource' => 'products',
					'id' => $id_prod,   //120018
				);
				$image = (int)$webService->get($opt)->product->id_default_image; //->images; //->attributes();

				if (!$image) {
					$needimage = true;
					$url_img_api = PS_SHOP_PATH.'/api/images/products/'.$id_prod;
				}
				else $needimage = false;
			
			} catch (PrestaShopWebserviceException $e) {
				$trace = $e->getTrace();
				if ($trace[0]['args'][0] == 404) echo 'Bad ID';
				else if ($trace[0]['args'][0] == 401) echo 'Bad auth key';
				else echo 'Other error<br />'.$e->getMessage();
				slog ($e->getMessage());
				echo "\n*E Check images\n";
			}
			
		}
		if ($needimage) {
			$urlimg  = 'http://textbook.ru/static/book/'.$thousand.'/'.$s_id.'.jpg';
			echo " | img=".$urlimg.'..';
			$cnerr = 0;
			while ($cnerr < 1 and !($cpres=copy ($urlimg, $tmpimg))) {  // 10
				$cnerr++; 
				echo '.'; 
			}
			if (!$cpres) { 
				echo "X\n*E IMG _continue_ "; 
				slog('img-'.$s_id); 
				$cnt_err ++;
			} else {
				//$url = PS_SHOP_PATH.'/api/images/products/'.(int)$created_product->product->id;
				$image_path = 'g:\perm-books\img.png';
				$ch = curl_init();
				curl_setopt($ch, CURLOPT_URL, $url_img_api);
				curl_setopt($ch, CURLOPT_POST, true);
				curl_setopt($ch, CURLOPT_USERPWD, PS_WS_AUTH_KEY.':');
				curl_setopt($ch, CURLOPT_POSTFIELDS, array('image' => '@'.$tmpimg));
				curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
				$result = curl_exec($ch);
				curl_close($ch);
				print " *IMG_UPLOADED ";
				$cnt_img ++;

			}
		}
		if ($cnt_scr++ >= 9) {
			$cnt_scr = 0;
			print "\n\n*S ====[NEW: $cnt_new][OLD: $cnt_old][IMG: $cnt_img][ERR: $cnt_err]====\n";

		}
                $errortrigger = 0;
	}			// while



	fclose ($fp);
	
	unlink ($currentpositionfile); 
	echo "\n--------8<------------------------------- end ----------------------------->8----------\n";



	function extract_data ($content, $str, $str_end) {
			$p = strpos ($content, $str, 0);
			if ($p === false) return 'Нет описания';
			$p += strlen($str);
			$p_end = strpos ($content, $str_end, $p);
			return substr ($content, $p, $p_end - $p);
	}

	function slog ($strlog) {
		$lf = fopen ('errors.txt', 'a');
		fputs ($lf, $strlog."\n");
		fclose ($lf);
	}

	function calc_price ($percent, $inprice) {
		$k = 1+$percent/100;
		$pr['opt'] = $inprice * $k;
		//$pr['rozn'] = $pr['opt'] * 1.37;
		$pr['rozn'] = $pr['opt'] * 1.25;
		//$pr['rozn'] = rand (1, (int)($pr['opt']/2)+1);
		$pr['opt'] = round ($pr['opt'], 2);
		$pr['rozn'] = round ($pr['rozn'], 0);
		return $pr;

	}


	function calc_p ($abr_izd) {

		if (strstr($abr_izd, 'М.: Академкнига/Учебник')) 	return array ('percent' => 25, 	'pub' => 1); 
		if (strstr($abr_izd, 'М.: АСТ')) 			return array ('percent' => 25, 	'pub' => 2); 
		if (strstr($abr_izd, 'М.: Баласс')) 			return array ('percent' => 25,	'pub' => 3); 
		if (strstr($abr_izd, 'М.: Просвещение')) 		return array ('percent' => 25,	'pub' => 4);
		if (strstr($abr_izd, 'М.: Вентана-Граф')) 		return array ('percent' => 25,	'pub' => 5);
		if (strstr($abr_izd, 'М.: Вита-Пресс')) 		return array ('percent' => 25,	'pub' => 6); 
		if (strstr($abr_izd, 'Самара: ИД ""Федоров""')) 	return array ('percent' => 25, 	'pub' => 7); 
		if (strstr($abr_izd, 'Смоленск: Ассоциация XXI')) 	return array ('percent' => 25,	'pub' => 8); 
		if (strstr($abr_izd, 'М.: Оникс')) 			return array ('percent' => 25, 	'pub' => 9); 
		if (strstr($abr_izd, 'Обнинск: Титул')) 		return array ('percent' => 25,	'pub' => 10);
		if (strstr($abr_izd, 'М.: Версия')) 			return array ('percent' => 25,	'pub' => 11);
		if (strstr($abr_izd, 'М.: АСТ-Пресс')) 			return array ('percent' => 25, 	'pub' => 12);
		if (strstr($abr_izd, 'М.: Март')) 			return array ('percent' => 25,	'pub' => 13);
		if (strstr($abr_izd, 'М.: МЦНМО')) 			return array ('percent' => 25, 	'pub' => 14);
		if (strstr($abr_izd, 'М.: Ювента')) 			return array ('percent' => 25, 	'pub' => 15);
		if (strstr($abr_izd, 'М.: Мнемозина')) 			return array ('percent' => 25,	'pub' => 16);
		if (strstr($abr_izd, 'М.: БИНОМ. Лаборатория знаний')) 	return array ('percent' => 25,	'pub' => 17);
		if (strstr($abr_izd, 'М.: Русское слово')) 		return array ('percent' => 25,	'pub' => 18);
		if (strstr($abr_izd, 'СПб.: Питер')) 			return array ('percent' => 25,	'pub' => 19);
		if (strstr($abr_izd, 'М.: Владос')) 			return array ('percent' => 25,	'pub' => 20);
		if (strstr($abr_izd, 'СПб.: Просвещение')) 		return array ('percent' => 25,	'pub' => 21);
		if (strstr($abr_izd, 'М.: Вербум-М')) 			return array ('percent' => 25,	'pub' => 22);
		if (strstr($abr_izd, 'М.: Академия')) 			return array ('percent' => 25,	'pub' => 23);
		if (strstr($abr_izd, 'М.: Интеллект-Центр')) 		return array ('percent' => 25,	'pub' => 24);
		if (strstr($abr_izd, 'М.: Олма Медиа Групп')) 		return array ('percent' => 25,	'pub' => 25);
		if (strstr($abr_izd, 'М.: Высшая школа')) 		return array ('percent' => 25,	'pub' => 26);
		if (strstr($abr_izd, 'М.: РОСТкнига')) 			return array ('percent' => 25, 	'pub' => 27);
		if (strstr($abr_izd, 'М.: Дрофа')) 			return array ('percent' => 25,	'pub' => 28);
		if (strstr($abr_izd, 'РнД.: Легион')) 			return array ('percent' => 25,	'pub' => 29);
		if (strstr($abr_izd, 'М.: Илекса')) 			return array ('percent' => 25, 	'pub' => 30);
		if (strstr($abr_izd, 'М.: Экзамен')) 			return array ('percent' => 25, 	'pub' => 31);
		if (strstr($abr_izd, 'М.: Самовар')) 			return array ('percent' => 25, 	'pub' => 32);
		if (strstr($abr_izd, 'Волгоград: Учитель')) 		return array ('percent' => 25,	'pub' => 33);
		if (strstr($abr_izd, 'М.: Мозаика - синтез')) 		return array ('percent' => 25, 	'pub' => 34);
		if (strstr($abr_izd, 'М.: Росмэн')) 			return array ('percent' => 25,	'pub' => 35);
		if (strstr($abr_izd, 'СПб.: Антология')) 		return array ('percent' => 25, 	'pub' => 36);
		if (strstr($abr_izd, 'М.: Национальное образование')) 	return array ('percent' => 25, 	'pub' => 37);
		if (strstr($abr_izd, 'РнД.: Феникс')) 			return array ('percent' => 25,	'pub' => 38);

		return false;

	}
	
	function pereplet_id ($perepletstr) {
		if (strstr($perepletstr, "Digipack"))	return 11;
		if (strstr($perepletstr, "DVD"))		return 12;
		if (strstr($perepletstr, "Jewel"))	return 13;
		if (strstr($perepletstr, "Интегральный переплет"))	return 14;
		if (strstr($perepletstr, "Картон"))	return 15;
		if (strstr($perepletstr, "Обложка"))	return 16;
		if (strstr($perepletstr, "Папка"))	return 17;
		if (strstr($perepletstr, "Переплет"))	return 18;
		
		return 19;		// Прочие
	}

	function grp ($name, $abr_izd, $fp='') {

		/* 1кл=3 2кл=4 3кл=5 4кл=6 5кл=7 6кл=8 7кл=9 8кл=10 9кл=11 10кл=12 11кл=13 */
		//$grp = '2'; //// DEFAUT "главная"

		//return $grp;

		$subjects = array (
			array (	'id' => 52, 'pattern' => '(информат|компьют|программи|икт\b|excel|html|web|openoffice|flash|scratch|microsoft|иск.*интеллект)' ),
			array (	'id' => 25, 'pattern' => '(биологи[я|ч|и]|микробиолог)' ),
			array (	'id' => 26, 'pattern' => '(математик[а|и]|арифмет|математическ)' ),
			array (	'id' => 27, 'pattern' => 'алгебр[а|е|ы]' ),
			array (	'id' => 28, 'pattern' => 'геометри[я|ч|и|е]' ),
			array (	'id' => 29, 'pattern' => 'физи[ч|к]' ),
			array (	'id' => 30, 'pattern' => '(хими[я|ч|и|ю]|окисл.*восст)' ),
			array (	'id' => 31, 'pattern' => 'географи[я|ч|и]' ),
			array (	'id' => 32, 'pattern' => 'истори[я|ч|и]' ),
			array (	'id' => 34, 'pattern' => '(обществознани[я|е|и|ю]|право\b)' ),
			array (	'id' => 51, 'pattern' => 'естествознан' ),
			array (	'id' => 35, 'pattern' => 'тригонометри[я|ч|и|ю]' ),
			array (	'id' => 36, 'pattern' => 'словар(ь|ик)' ),
			array (	'id' => 37, 'pattern' => 'рус.*яз(ык|\.)' ),
			array (	'id' => 38, 'pattern' => '(англ.*яз(ык|\.)|english|английск)' ),
			array (	'id' => 39, 'pattern' => '(фр.*яз(ык|\.)|francais|французск)' ),
			array (	'id' => 40, 'pattern' => '(нем.*яз(ык|\.)|deutsch|немецк)' ),
			array (	'id' => 41, 'pattern' => 'язык' ),				// прочие языки
			array (	'id' => 33, 'pattern' => 'литератур[а|н|е]' ),			// литература после русского языка
			array (	'id' => 47, 'pattern' => 'физ.*культ' ),
			array (	'id' => 42, 'pattern' => 'культур(а|н\.)' ),
			array (	'id' => 43, 'pattern' => 'психолог' ),				// (ии|ическ|ия|о|а)
			array (	'id' => 44, 'pattern' => '(музык|сольфеджио|фортепиано|баян|ноты|аккордеон|синтезатор|гитар|скрипк|концерт)' ),
			array (	'id' => 45, 'pattern' => 'экономи[к|ч]' ),
			array (	'id' => 46, 'pattern' => 'черчени' ),
			array (	'id' => 48, 'pattern' => '(технология|по технологии)' ),
			array (	'id' => 49, 'pattern' => 'природоведени' ),
			array (	'id' => 50, 'pattern' => '(коррек|отклон)' ),
			array (	'id' => 53, 'pattern' => '(азбук|буквар)' ),
			array (	'id' => 55, 'pattern' => '(труд\b|труду\b|труд.обуч|трудовое обучение)' ),
			array (	'id' => 56, 'pattern' => '(обж\b|безопасност)' ),
			array (	'id' => 57, 'pattern' => 'энц' ),
			array (	'id' => 54, 'pattern' => '(изо\b|изобр)' ),
		);

		// паттерн: /\b.pattern./iu

		foreach ($subjects as $subject) {
			if (preg_match('/\b'.$subject['pattern'].'/iu', $name)) {
				return $subject['id'];
			}
		}
		return 61;



		if (strstr($name, ' 1 кл')) 	$grp = '12';
		if (strstr($name, '2 кл')) 	$grp = '13';
		if (strstr($name, '3 кл')) 	$grp = '14';
		if (strstr($name, '4 кл')) 	$grp = '15';
		if (strstr($name, '5 кл')) 	$grp = '16';
		if (strstr($name, '6 кл')) 	$grp = '17';
		if (strstr($name, '7 кл')) 	$grp = '18';
		if (strstr($name, '8 кл')) 	$grp = '19';
		if (strstr($name, '9 кл')) 	$grp = '20';
		if (strstr($name, '10 кл')) 	$grp = '21';
		if (strstr($name, '11 кл')) 	$grp = '22';
		if (strstr($name, 'ВУЗ')) 	$grp = '23';
		if (strstr($abr_izd, 'М.: Высшая школа')) $grp = '23';

//		if (strstr($name, 'ФП 2014/15')) 	$grp = '24';

		return $grp;
	}

	function smarty_modifier_mb_truncate($string, $length = 80, $etc = '...', $charset='UTF-8', $break_words = false, $middle = false) { 
	    if ($length == 0) 
	        return ''; 
  
	    if (strlen($string) > $length) { 
        	$length -= min($length, strlen($etc)); 
	        if (!$break_words && !$middle) { 
        	    $string = preg_replace('/\s+?(\S+)?$/u', '', mb_substr($string, 0, $length+1, $charset)); 
	        } 
	        if(!$middle) { 
        	    return mb_substr($string, 0, $length, $charset) . $etc; 
	        } else { 
        	    return mb_substr($string, 0, $length/2, $charset) . $etc . mb_substr($string, -$length/2, $charset); 
	        } 
	    } else { 
	        return $string; 
	    } 
	} 

	function checkege ($b_name, $b_year) {
		if (strstr($b_name, "ЕГЭ-2016"))
			return true;
		if (strstr($b_name, "ЕГЭ") && $b_year >= 2015 && !strstr($b_name, "2015"))
			return true;
		return false;
	}
	
	function explodeclasses ($b_name, &$strfeat) {
		preg_match_all("#(\d*)-*(\d+)\s*кл#isU", $b_name, $_classes);
		if (count($_classes[0]) > 0) {
			$arrcl = array ();
			$arrclfeat = array ();
			foreach ($_classes[0] as $_key => $_val) {
				if ((int)$_classes[1][$_key] > 0) {
					for ($_i = (int)$_classes[1][$_key]; $_i <= (int)$_classes[2][$_key]; $_i ++) 
						$arrcl[] = $_i;
					$arrclfeat[] = (int)$_classes[1][$_key].'-'.(int)$_classes[2][$_key].' кл.';
				} else {
					$arrcl[] = (int)$_classes[2][$_key];
					$arrclfeat[] = (int)$_classes[2][$_key].' кл.';
				}
			}
			$strfeat = implode(', ', $arrclfeat );
			return array_unique ($arrcl);
		}
		return false;
	}

	function addfeature (&$webService, &$resources_product, &$xml_feature, $id_feature, $value, $custom=0, &$cache) {
			if (!$id_feature)
				return;
			$value = trim ($value);
			if (!strlen ($value)) {
				return;
			}
			if(isset($cache[$value]))
				$created_feature_id = $cache[$value];
			else {
				$errortrigger2 = 0;
				loop2:
				if ($errortrigger2 >= 10) {
					return;
				}

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
					print '~';
					$errortrigger2++;
					goto loop2;
					//die ();
					//return false;
				}
			}
			$_i = 0;
			foreach ($resources_product->associations->product_features->product_feature as $_t) {
				if ($_t->id == $id_feature) {
					if ($_t->id_feature_value == $created_feature_id) {
						print '+';
						return;
					}
					unset ($resources_product->associations->product_features->product_feature[$_i]);
				}
				$_i++;
			}


			$newFeature = $resources_product->associations->product_features->addChild('product_feature');
			$newFeature->addChild('id', $id_feature);
			$newFeature->addChild('id_feature_value', $created_feature_id);
			print '*';
	}


?>
