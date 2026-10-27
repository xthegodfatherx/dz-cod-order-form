<?php
define('ABSPATH','/');define('MB_IN_BYTES',1048576);
function __($s){return $s;} function esc_attr__($s){return $s;}
function apply_filters($h,$v){return $v;} function wp_json_encode($v){return json_encode($v,JSON_UNESCAPED_UNICODE);}
function untrailingslashit($s){return rtrim($s,'/');} function wp_strip_all_tags($s){return strip_tags($s);}
function remove_accents($s){return iconv('UTF-8','ASCII//TRANSLIT',$s);} function is_wp_error($x){return false;}
$GLOBALS['Q']=[];$GLOBALS['LOG']=[];
function wp_safe_remote_request($url,$args){$GLOBALS['LOG'][]=[$args['method'],$url,$args['headers'],$args['body']??null];return array_shift($GLOBALS['Q']);}
function wp_remote_retrieve_body($r){return $r['body'];} function wp_remote_retrieve_response_code($r){return $r['code'];}
require dirname(__DIR__, 2) . '/includes/class-dzcod-courier-api.php';
$parcel=['reference'=>'1052','name'=>'Amine Benali','phone'=>'0555123456','wilaya_code'=>'28','wilaya_name'=>"M'Sila",'commune'=>'Bou Saada','address'=>'Cité 20 logts','amount'=>4100.0,'products'=>'Miel x1','stopdesk'=>false,'note'=>''];
$s=['yalidine_id'=>'ID','yalidine_token'=>'TK','yalidine_from_wilaya'=>'Tizi Ouzou','procolis_token'=>'PT','procolis_key'=>'PK','procolis_confirmed'=>1,'ecotrack_token'=>'ET','ecotrack_url'=>''];
function q($code,$arr){$GLOBALS['Q'][]=['code'=>$code,'body'=>json_encode($arr)];}
function show($label,$r){$l=end($GLOBALS['LOG']);echo "== $label\n  ",$l[0]," ",$l[1],"\n  headers: ",json_encode($l[2]),"\n  body: ",$l[3],"\n  result: ",json_encode($r),"\n";}

function check($l,$c){echo ($c?'PASS ':'FAIL ').$l."\n";}
function last(){return end($GLOBALS['LOG']);}
q(200,['1052'=>['success'=>true,'tracking'=>'yal-ABC123','label'=>'https://yalidine.app/l/1']]);
$r=DZCOD_Courier_API::create('yalidine',$s,$parcel); $b=json_decode(last()[3],true);
check('Yalidine home: tracking + label',$r['ok'] && $r['tracking']==='yal-ABC123' && $r['label']==='https://yalidine.app/l/1');
check('Yalidine: POST /v1/parcels/ with X-API headers, array body',last()[1]==='https://api.yalidine.app/v1/parcels/' && last()[2]['X-API-ID']==='ID' && isset($b[0]['order_id']));
check('Yalidine: name split, price int, not stopdesk',$b[0]['firstname']==='Amine' && $b[0]['familyname']==='Benali' && $b[0]['price']===4100 && $b[0]['is_stopdesk']===false);
$p2=$parcel;$p2['stopdesk']=true;
q(200,['data'=>[['center_id'=>'281001','commune_name'=>"M'Sila"],['center_id'=>'281101','commune_name'=>'Bou Saâda']]]);
q(200,['1052'=>['success'=>'true','tracking'=>'yal-DESK1','label'=>'']]);
$r=DZCOD_Courier_API::create('yalidine',$s,$p2); $b=json_decode(last()[3],true);
check('Yalidine stop desk: commune matched despite accent',$r['ok'] && $b[0]['stopdesk_id']==='281101');
q(200,['1052'=>['success'=>false,'message'=>'Commune introuvable']]);
$r=DZCOD_Courier_API::create('yalidine',$s,$parcel); check('Yalidine refusal message surfaced',!$r['ok'] && $r['error']==='Commune introuvable');
q(200,['Colis'=>[['MessageRetour'=>'Good','Tracking'=>'ZR-889900']]]);
$r=DZCOD_Courier_API::create('zrexpress',$s,$parcel); $b=json_decode(last()[3],true);
check('ZR Express: tracking, POST /add_colis, token/key headers',$r['ok'] && $r['tracking']==='ZR-889900' && last()[1]==='https://procolis.com/api_v1/add_colis' && last()[2]['token']==='PT');
check('ZR Express: legacy wilaya id + misspelled Confrimee field',$b['Colis'][0]['IDWilaya']==='28' && $b['Colis'][0]['Confrimee']==='1');
q(200,['Colis'=>[['MessageRetour'=>'Double Tracking']]]);
$r=DZCOD_Courier_API::create('zrexpress',$s,$parcel); check('ZR Express duplicate refused',!$r['ok'] && $r['error']==='Double Tracking');
q(200,['success'=>true,'tracking'=>'ECO-77']);
$r=DZCOD_Courier_API::create('dhd',$s,$parcel); $b=json_decode(last()[3],true);
check('Ecotrack (DHD): Bearer, /api/v1/create/order, tracking',$r['ok'] && $r['tracking']==='ECO-77' && last()[1]==='https://dhd.ecotrack.dz/api/v1/create/order' && last()[2]['Authorization']==='Bearer ET' && $b['code_wilaya']===28);
q(422,['message'=>'The given data was invalid.','errors'=>['commune'=>['invalid']]]);
$r=DZCOD_Courier_API::create('dhd',$s,$parcel); check('Ecotrack 422 shows field errors',!$r['ok'] && strpos($r['error'],'commune')!==false);
$n=count($GLOBALS['LOG']); $p3=$parcel;$p3['amount']=200000;
$r=DZCOD_Courier_API::create('yalidine',$s,$p3); check('Yalidine >150k refused locally, no request',!$r['ok'] && count($GLOBALS['LOG'])===$n);
