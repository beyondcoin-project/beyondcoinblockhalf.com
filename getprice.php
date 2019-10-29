<?php
$data = file_get_contents('https://api.coingecko.com/api/v3/coins/beyondcoin/tickers');
$price = json_decode($data, true);
$byndPrice = (float)$price["last"];

if ($price <= 1.0) {
	die(); 
}

$file = "price.txt";
$fh = fopen($file, 'w') or die("can't open file");
fwrite($fh, $byndPrice);
fclose($fh);
?>
