<?php
define('ABSPATH','/');define('MB_IN_BYTES',1048576);function __($s){return $s;} function apply_filters($h,$v){return $v;}
function remove_accents($s){return iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$s)?:$s;}
require dirname(__DIR__, 2) . '/includes/class-dzcod-courier-api.php';
$cases=['Livré'=>'delivered','Livré et encaissé'=>'delivered','En cours de livraison'=>'out_for_delivery','Sorti en livraison'=>'out_for_delivery','Tentative échouée'=>'delivery_attempted','Client injoignable'=>'delivery_attempted','Retour vers vendeur'=>'return_in_transit','Retourné au vendeur'=>'returned','Retour reçu'=>'returned','Annulé'=>'cancelled','En transit vers Oran'=>'in_transit','Centre de tri Alger'=>'in_transit','Ramassé'=>'picked_up','En attente du ramassage'=>'pending','En préparation'=>'pending','Attribué au livreur'=>'unknown','تم التسليم'=>'delivered','مرتجع'=>'returned','خرج للتوصيل'=>'out_for_delivery','Delivered'=>'delivered',''=>'unknown'];
$f=0;foreach($cases as $in=>$exp){$got=DZCOD_Courier_API::normalize_status($in);if($got!==$exp){$f++;echo "FAIL '$in' → $got (expected $exp)\n";}}
echo count($cases)-$f,"/",count($cases)," status phrases mapped correctly\n";
