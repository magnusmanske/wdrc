<?php

ini_set('memory_limit','1500M');

error_reporting(E_ERROR|E_CORE_ERROR|E_COMPILE_ERROR); #|E_ALL
ini_set('display_errors', 'On');

require_once ( '/data/project/wdrc/scripts/WDRC.php' ) ;

function finish ( $status = 'OK' ) {
	global $out , $wdrc , $format ;
	$callback = $wdrc->tfc->getRequest ( 'callback' , '' ) ;
	$out['status'] = $status ;
	if ( $format == 'json' ) {
		header('Content-type: application/json');
		if ( $callback != '' ) print "{$callback}(" ;
		print json_encode($out);
		if ( $callback != '' ) print ")" ;
	} else if ( $format == 'jsonl' ) {
		if ( $status != 'OK' ) print "{$status}\n" ;
		// Done in add_data()
	} else if ( $format == 'html' ) {
		// Done in add_data()
	} else {
		header('Content-type: text/html');
		if ( !isset($out['data']) ) {
			print "<pre>" ;
			print json_encode($out,JSON_PRETTY_PRINT);
			print "</pre>" ;
		}
	}

	$wdrc->tfc->flush();
	exit(0);
}

function add_data ( $d ) {
	global $out , $format ;
	if ( $format == 'jsonl' ) {
		print json_encode($d)."\n" ;
	} else if ( $format == 'html' ) {
		$q = '' ;
		$h = "<div>" ;
		foreach ( $d as $k => $v ) {
			$h .= "<span style='margin-right:0.5rem;'>" ;
			if ( $k == 'item' ) {
				$h .= "<a href='https://www.wikidata.org/wiki/{$v}' target='_blank'>{$v}</a>" ;
				$q = $v ;
			} else if ( $k == 'revision' ) {
				$h .= "<a href='https://www.wikidata.org/w/index.php?title={$q}&oldid={$v}' target='_blank'>rev {$v}</a>" ; 
			} else {
				$h .= "{$k}: {$v}" ;
			}
			$h .= "</span>" ;
		}
		$h .= "</div>\n" ;
		print $h ;
	} else {
		$out['data'][] = $d ;
	}
}

$wdrc = new WDRC ;

$out = [] ;
$action = $wdrc->tfc->getRequest ( 'action' , '' ) ;
$format = $wdrc->tfc->getRequest ( 'format' , 'jsonl' ) ;
if ( $format == 'jsonl' ) header('Content-type: text/plain');
if ( $format == 'html' ) header('Content-type: text/html');


if ( $action == 'lag' ) {

	$timestamp = $wdrc->get_key_value ( 'timestamp' ) ;
	$datetime1 = new DateTime($timestamp);
	$datetime2 = new DateTime;
	$diff = $datetime1->diff($datetime2);
	$out['lag'] = "{$diff->y} years, {$diff->m} months, {$diff->d} days, {$diff->h} hours, {$diff->i} minutes, {$diff->s} seconds" ;
	$out['lag'] = preg_replace ( '|^(0 \S+\s*)+|' , '' , $out['lag'] ) ;

} else if ( $action == 'properties' ) {

	$db = $wdrc->get_db_tool() ;

	$props = $wdrc->tfc->getRequest ( 'properties' , '' ) ; # Pxxx
	$props = preg_replace('|[^0-9,]|','',$props) ;
	if ( $props == '' ) finish ( 'Property ID required' ) ;

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

	$sql = "SELECT * FROM `statements` WHERE `property` IN ({$props}) AND `timestamp`>='{$since}'" ;
	if ( count($type)>0 ) $sql .= " AND `change_type` IN ('".implode("','",$type)."')" ;
	$sql .= " ORDER BY `timestamp`" ;
	#$out['sql'] = $sql ;

	$out['data'] = [] ;
	$result = $wdrc->tfc->getSQL ( $db , $sql ) ;
	while($o = $result->fetch_object()) {
		$d = ['item'=>"Q{$o->item}",'property'=>"P{$o->property}",'revision'=>$o->revision,'timestamp'=>$o->timestamp,'type'=>$o->change_type] ;
		add_data ( $d ) ;
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
		$d = ['item'=>"Q{$o->item}",'revision'=>$o->revision,'timestamp'=>$o->timestamp,'type'=>$o->change_type,'element'=>$o->type,'text'=>$o->text] ;
		add_data ( $d ) ;
	}

} else if ( $action == 'items' ) {

	$out['data'] = [] ;
	$db = $wdrc->get_db_tool() ;

	$items = $wdrc->tfc->getRequest ( 'items' , '' ) ; # One Qid per line
	$items = preg_replace('/[,;|]/',"\n",$items) ;
	$items = explode ( "\n" , $items ) ;
	$items_to_check = [] ;
	foreach ( $items AS $q ) {
		if ( preg_match('|^[Qq](\d+)$|',$q,$m) ) $items_to_check[] = $m[1] ;
	}
	if ( count($items_to_check) == 0 ) finish ( '"items" parameter is required and must me non-empty' ) ;
	$items_to_check = implode(',',$items_to_check) ;

	# Labels
	$sql = "SELECT *,(SELECT `value` FROM `texts` WHERE `texts`.`id`=`language`) AS `text` FROM `labels` WHERE `timestamp`>='$since'" ;
	$sql .= " AND `item` IN ({$items_to_check})" ;
	$result = $wdrc->tfc->getSQL ( $db , $sql ) ;
	while($o = $result->fetch_object()) {
		$d = ['item'=>"Q{$o->item}",'revision'=>$o->revision,'timestamp'=>$o->timestamp,'type'=>$o->change_type,'element'=>$o->type,'text'=>$o->text] ;
		add_data ( $d ) ;
	}

	$sql = "SELECT * FROM `statements` WHERE `timestamp`>='{$since}'" ;
	$sql .= " AND `item` IN ({$items_to_check})" ;
	$result = $wdrc->tfc->getSQL ( $db , $sql ) ;
	while($o = $result->fetch_object()) {
		$d = ['item'=>"Q{$o->item}",'property'=>"P{$o->property}",'revision'=>$o->revision,'timestamp'=>$o->timestamp,'type'=>$o->change_type] ;
		add_data ( $d ) ;
	}



} else {
	$out['actions'] = ['lag','property','text'] ;
	$out['format'] = ['json','jsonl','html'] ;
}


finish() ;

?>