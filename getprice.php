<?php
$data = file_get_contents('https://apiv2.bitcoinaverage.com/indices/global/ticker/LTCUSD');
$price = json_decode($data, true);
$ltcPrice = (float)$price["last"];

if ($price <= 1.0) {
	die(); 
}

$file = "price.txt";
$fh = fopen($file, 'w') or die("can't open file");
fwrite($fh, $ltcPrice);
fclose($fh);
?>
