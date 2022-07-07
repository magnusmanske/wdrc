<?php

ini_set('memory_limit','1500M');

error_reporting(E_ERROR|E_CORE_ERROR|E_COMPILE_ERROR); #|E_ALL
ini_set('display_errors', 'On');

require_once ( '/data/project/wdrc/scripts/WDRC.php' ) ;

function finish ( $status = 'OK' ) {
	global $out , $wdrc ;
	$format = $wdrc->tfc->getRequest ( 'format' , 'json' ) ;
	$callback = $wdrc->tfc->getRequest ( 'callback' , '' ) ;
	$out['status'] = $status ;
	if ( $format == 'json' ) {
		header('Content-type: application/json');
		if ( $callback != '' ) print "{$callback}(" ;
		print json_encode($out);
		if ( $callback != '' ) print ")" ;
	} else if ( $format == 'jsonl' ) {
		header('Content-type: text/plain');
		foreach ( $out['data']??[] AS $line ) {
			print json_encode($line)."\n" ;
		}
	} else {
		header('Content-type: text/html');
		print "<pre>" ;
		print json_encode($out,JSON_PRETTY_PRINT);
		print "</pre>" ;
	}

	$wdrc->tfc->flush();
	exit(0);
}

$wdrc = new WDRC ;

$out = [] ;
$action = $wdrc->tfc->getRequest ( 'action' , '' ) ;

if ( $action == 'lag' ) {

	$timestamp = $wdrc->get_key_value ( 'timestamp' ) ;
	$datetime1 = new DateTime($timestamp);
	$datetime2 = new DateTime;
	$diff = $datetime1->diff($datetime2);
	$out['lag'] = "{$diff->y} years, {$diff->m} months, {$diff->d} days, {$diff->h} hours, {$diff->i} minutes, {$diff->s} seconds" ;
	$out['lag'] = preg_replace ( '|^(0 \S+\s*)+|' , '' , $out['lag'] ) ;

} else if ( $action == 'property' ) {

	$db = $wdrc->get_db_tool() ;

	$prop = $wdrc->tfc->getRequest ( 'property' , '' ) ; # Pxxx
	$prop = preg_replace('|\D|','',$prop)*1 ;
	if ( $prop == 0 ) finish ( 'Property ID required' ) ;

	$type = trim ( $wdrc->tfc->getRequest ( 'type' , '' ) ) ; # added,changed,removed
	if ( $type == '' ) $type = [] ;
	else {
		$type = $db->real_escape_string ( $type ) ;
		$type = explode ( ',' , $type ) ;
	}
	
	$since = $wdrc->tfc->getRequest ( 'since' , '' ) ; # 20220101020304
	$since = preg_replace('|\D|','',$since) ;
	if ( $since == '' ) finish ( '"since" parameter required (eg 20220101020304)' ) ;
	while ( strlen($since) < 14 ) $since .= '0' ;

	$sql = "SELECT * FROM `statements` WHERE `property`={$prop} AND `timestamp`>='{$since}'" ;
	if ( count($type)>0 ) $sql .= " AND `change_type` IN ('".implode("','",$type)."')" ;
	$sql .= " ORDER BY `timestamp`" ;
	#$out['sql'] = $sql ;

	$out['data'] = [] ;
	$result = $wdrc->tfc->getSQL ( $db , $sql ) ;
	while($o = $result->fetch_object()) {
		$out["data"][] = ['item'=>"Q{$o->item}",'revision'=>$o->revision,'timestamp'=>$o->timestamp,'type'=>$o->change_type] ;
	}

} else if ( $action == 'text' ) {

	$db = $wdrc->get_db_tool() ;

	# Get text IDs if required
	$text_ids = [] ;
	$text = trim ( $wdrc->tfc->getRequest ( 'text' , '' ) ) ; # de,enwiki,...
	if ( $text != '' ) {
		$text = $db->real_escape_string ( $text ) ;
		$text = explode ( ',' , $text ) ;
		$sql = "SELECT `id` FROM `texts` WHERE `value` IN ('".implode("','",$text)."')" ;
		$result = $wdrc->tfc->getSQL ( $db , $sql ) ;
		while($o = $result->fetch_object()) $text_ids[] = $o->id ;
	}

	$since = $wdrc->tfc->getRequest ( 'since' , '' ) ; # 20220101020304
	$since = preg_replace('|\D|','',$since) ;
	if ( $since == '' ) finish ( '"since" parameter required (eg 20220101020304)' ) ;
	while ( strlen($since) < 14 ) $since .= '0' ;

	$type = trim ( $wdrc->tfc->getRequest ( 'type' , '' ) ) ; # added,changed,removed
	if ( $type == '' ) $type = [] ;
	else {
		$type = $db->real_escape_string ( $type ) ;
		$type = explode ( ',' , $type ) ;
	}

	$elements = trim ( $wdrc->tfc->getRequest ( 'element' , '' ) ) ; # labels,aliases,descriptions,sitelinks
	if ( $elements == '' ) $elements = [] ;
	else {
		$elements = $db->real_escape_string ( $elements ) ;
		$elements = explode ( ',' , $elements ) ;
	}

	$sql = "SELECT *,(SELECT `value` FROM `texts` WHERE `texts`.`id`=`language`) AS `text` FROM `labels` WHERE `timestamp`>='$since'" ;
	if ( count($type)>0 ) $sql .= " AND `change_type` IN ('".implode("','",$type)."')" ;
	if ( count($elements)>0 ) $sql .= " AND `type` IN ('".implode("','",$elements)."')" ;
	if ( count($text_ids)>0 ) $sql .= " AND `language` IN (".implode(",",$text_ids).")" ;
	$sql .= " ORDER BY `timestamp`" ;
	#$out['sql'] = $sql ;

	$out['data'] = [] ;
	$result = $wdrc->tfc->getSQL ( $db , $sql ) ;
	while($o = $result->fetch_object()) {
		$out["data"][] = ['item'=>"Q{$o->item}",'revision'=>$o->revision,'timestamp'=>$o->timestamp,'type'=>$o->change_type,'element'=>$o->type,'text'=>$o->text] ;
	}


} else {
	$out['actions'] = ['lag','property','text'] ;
	$out['format'] = ['json','jsonl','html'] ;
}


finish() ;

?>