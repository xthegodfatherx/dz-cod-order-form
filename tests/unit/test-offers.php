<?php
function wp_json_file_decode($f,$o=array()){return json_decode(file_get_contents($f),true);}
define('ABSPATH','/');define('DZCOD_DIR',dirname(__DIR__, 2) . '/');
function __($s){return $s;} function apply_filters($h,$v){return $v;} function add_action(){} function wc_get_price_decimals(){return 0;} function absint($v){return abs((int)$v);}
$GLOBALS['META']=[];function get_post_meta($id,$k,$single){return $GLOBALS['META'][$id][$k]??'';}
require DZCOD_DIR.'includes/class-dzcod-locations.php'; require DZCOD_DIR.'includes/class-dzcod-offers.php'; require DZCOD_DIR.'includes/class-dzcod-antifraud.php'; require DZCOD_DIR.'includes/class-dzcod-leads.php';
function check($l,$c){echo ($c?'PASS ':'FAIL ').$l."\n";}
$GLOBALS['META'][5]['_dzcod_offers']=[
 ['qty'=>'3','price'=>'8000','percent'=>'','label'=>'','badge'=>'Best','free_shipping'=>1],
 ['qty'=>'1','price'=>'','percent'=>'','label'=>'','badge'=>'','free_shipping'=>0],
 ['qty'=>'2','price'=>'','percent'=>'10','label'=>'Duo','badge'=>'','free_shipping'=>0],
 ['qty'=>'2','price'=>'1','percent'=>'','label'=>'dup qty','badge'=>'','free_shipping'=>0],
 ['qty'=>'0','price'=>'1'],['qty'=>'150','price'=>'1'],
 ['qty'=>'4','price'=>'','percent'=>'95'],
];
$o=DZCOD_Offers::get(5);
check('sorted by qty, invalid/duplicate dropped: '.implode(',',array_column($o,'qty')),array_column($o,'qty')===[1,2,3,4]);
check('1 piece = unit price',DZCOD_Offers::total($o[0],3500)==3500.0);
check('2 pieces -10% = 6300',DZCOD_Offers::total($o[1],3500)==6300.0);
check('3 pieces fixed 8000 + free shipping',DZCOD_Offers::total($o[2],3500)==8000.0 && $o[2]['free_shipping']===true);
check('discount capped at 90%',$o[3]['percent']==90.0 && DZCOD_Offers::total($o[3],3500)==1400.0);
check('no offers → empty',DZCOD_Offers::get(6)===[]);
check('WhatsApp link international',strpos(DZCOD_Leads::whatsapp_url('0555123456','Salam'),'https://wa.me/213555123456?text=Salam')===0);

// Free delivery per product.
$GLOBALS['META'][8]['_dzcod_free_delivery']=['mode'=>'desk','wilayas'=>['16',' 9','99','abc']];
$GLOBALS['META'][9]['_dzcod_free_delivery']=['mode'=>'all','wilayas'=>[]];
$GLOBALS['META'][10]['_dzcod_free_delivery']=['mode'=>'bogus'];
$f8=DZCOD_Offers::free_delivery(8); $f9=DZCOD_Offers::free_delivery(9);
check('free delivery: wilaya list cleaned (16, 09; 99/abc dropped)',$f8['wilayas']===['16','09']);
check('free delivery: desk only applies to desk in listed wilaya',DZCOD_Offers::free_delivery_applies($f8,'16','desk') && !DZCOD_Offers::free_delivery_applies($f8,'16','home'));
check('free delivery: not in other wilayas',!DZCOD_Offers::free_delivery_applies($f8,'31','desk'));
check('free delivery: "all" applies everywhere, both types',DZCOD_Offers::free_delivery_applies($f9,'68','home') && DZCOD_Offers::free_delivery_applies($f9,'01','desk'));
check('free delivery: invalid mode = off',DZCOD_Offers::free_delivery(10)['mode']==='' && !DZCOD_Offers::free_delivery_applies(DZCOD_Offers::free_delivery(10),'16','home'));
