<?php
$data = file_get_contents('https://api.bitfinex.com/v1/pubticker/LTCUSD');
$price = json_decode($data, true);
$ltcPrice = (float)$price["last_price"];

if ($price <= 1.0) {
	die(); 
}

$file = "price.txt";
$fh = fopen($file, 'w') or die("can't open file");
fwrite($fh, $ltcPrice);
fclose($fh);
?>
