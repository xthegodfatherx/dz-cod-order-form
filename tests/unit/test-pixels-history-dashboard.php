<?php
define('ABSPATH','/');define('DZCOD_DIR',dirname(__DIR__, 2) . '/');define('MB_IN_BYTES',1048576);define('MINUTE_IN_SECONDS',60);define('HOUR_IN_SECONDS',3600);define('DAY_IN_SECONDS',86400);
function __($s){return $s;} function apply_filters($h,$v){return $v;} function add_action(){} function add_filter(){} function wp_parse_args($a,$d){return array_merge($d,(array)$a);}
function remove_accents($s){return iconv('UTF-8','ASCII//TRANSLIT',$s);} function home_url($p=''){return 'https://shop.dz'.$p;} function wp_list_pluck($l,$f){return array_column($l,$f);}
function wp_salt($s){return 'SALT';} $GLOBALS['OPT']=[];function get_option($k,$d=false){return $GLOBALS['OPT'][$k]??$d;}
class I{public $p,$v,$q,$n;function __construct($p,$q,$n){$this->p=$p;$this->q=$q;$this->n=$n;} function get_product_id(){return $this->p;} function get_variation_id(){return 0;} function get_quantity(){return $this->q;} function get_name(){return $this->n;}}
class D{function getTimestamp(){return 1700000000;}}
class O{public $m=[];public $st='processing';function __construct($m=[]){$this->m=$m;} function get_meta($k){return $this->m[$k]??'';} function get_status(){return $this->st;} function get_id(){return 77;} function get_order_key(){return 'wc_order_abc';}
 function get_billing_phone(){return '0555 12 34 56';} function get_billing_first_name(){return ' Amine ';} function get_billing_city(){return 'Bou Saâda';} function get_total(){return 6300;} function get_currency(){return 'DZD';}
 function get_items(){return [new I(5,2,'Miel')];} function get_date_created(){return new D;} function get_order_number(){return '1077';}}
foreach(['locations','settings','antifraud','courier-api','tracking','history','pixels','dashboard','upsell'] as $f) require DZCOD_DIR."includes/class-dzcod-$f.php";
function check($l,$c){echo ($c?'PASS ':'FAIL ').$l."\n";}

$o=new O(['_dzcod_track'=>['fbp'=>'fb.1.1.2','fbc'=>'','ip'=>'105.1.2.3','ua'=>'Mozilla','url'=>'https://shop.dz/p/miel?fbclid=x','ttp'=>'tt1']]);
$e=DZCOD_Pixels::event_data($o);
check('event id matches browser dedup id',$e['event_id']==='dzcod-77');
check('phone in international format 213…',$e['phone']==='213555123456');
check('city normalised for hashing (bousaada)',$e['city']==='bousaada');
$m=DZCOD_Pixels::meta_payload($e);
check('Meta ph = sha256("213555123456")',$m['user_data']['ph'][0]===hash('sha256','213555123456'));
check('Meta fn trimmed+lowercased before hash',$m['user_data']['fn'][0]===hash('sha256','amine'));
check('Meta country hashed dz',$m['user_data']['country'][0]===hash('sha256','dz'));
check('Meta empty fbc omitted, fbp kept',!isset($m['user_data']['fbc']) && $m['user_data']['fbp']==='fb.1.1.2');
check('Meta custom_data value/currency/contents',$m['custom_data']['value']==6300 && $m['custom_data']['currency']==='DZD' && $m['custom_data']['contents'][0]==['id'=>'5','quantity'=>2]);
check('Meta no raw phone anywhere',strpos(json_encode($m),'555123456')===false);
$t=DZCOD_Pixels::tiktok_payload($e);
check('TikTok phone = sha256("+213555123456")',$t['user']['phone']===hash('sha256','+213555123456'));
check('TikTok event CompletePayment + same event_id',$t['event']==='CompletePayment' && $t['event_id']==='dzcod-77');
check('TikTok no raw phone',strpos(json_encode($t),'555123456')===false);

// History rule
$GLOBALS['OPT']['dzcod_settings']=['refusals_limit'=>2];DZCOD_Settings::flush();
check('2 returned, 1 delivered → risky',DZCOD_History::is_risky(['orders'=>3,'delivered'=>1,'returned'=>2,'rate'=>.66]));
check('2 returned, 5 delivered → not risky',!DZCOD_History::is_risky(['orders'=>7,'delivered'=>5,'returned'=>2,'rate'=>.28]));
check('1 returned, 0 delivered → below limit',!DZCOD_History::is_risky(['orders'=>1,'delivered'=>0,'returned'=>1,'rate'=>1]));
$GLOBALS['OPT']['dzcod_settings']=['refusals_limit'=>0];DZCOD_Settings::flush();
check('limit 0 → never risky',!DZCOD_History::is_risky(['orders'=>9,'delivered'=>0,'returned'=>9,'rate'=>1]));

// Dashboard state
$s=function($m,$st='processing'){$x=new O($m);$x->st=$st;return DZCOD_Dashboard::state($x);};
check('dashboard: parcel delivered',$s(['_dzcod_parcel_status'=>'delivered'])==='delivered');
check('dashboard: completed status counts delivered',$s([],'completed')==='delivered');
check('dashboard: returned status',$s([],'dzcod-returned')==='returned');
check('dashboard: cancelled',$s([],'cancelled')==='cancelled');
check('dashboard: tracking → shipped',$s(['_dzcod_tracking'=>'yal-1'])==='shipped');
check('dashboard: confirmed',$s(['_dzcod_confirmed'=>1])==='confirmed');
check('dashboard: new',$s([])==='new');

// Upsell token: bound to order key + product
$r=new ReflectionMethod('DZCOD_Upsell','token');$r->setAccessible(true);
$tok=$r->invoke(null,$o,9);
check('upsell token differs per product',$tok!==$r->invoke(null,$o,10));
check('upsell token is HMAC of id|key|product',hash_equals(hash_hmac('sha256','77|wc_order_abc|9','SALT'),$tok));
