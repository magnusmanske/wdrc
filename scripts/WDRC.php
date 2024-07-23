<?PHP

require_once ( '/data/project/magnustools/public_html/php/ToolforgeCommon.php' ) ;
// require_once ( getcwd().'/../../magnustools/public_html/php/ToolforgeCommon.php' ) ;

class WDRC {
	public $testing ;
	public $tfc ;
	public $dbwd ;
	public $db ;
	public $text_cache ;
	public $do_purge_old_entries = false ;
	public $purge_days_ago = 90 ;
	protected $max_recent_changes = 500 ;

	public function __construct () {
		$this->tfc = new ToolforgeCommon('WDRC') ;
	}

	protected function get_revisions_url ( $q , $rev_id_old , $rev_id_new ) {
		$url = "https://www.wikidata.org/w/api.php?action=query&prop=revisions&titles={$q}&rvprop=ids|content&rvstartid={$rev_id_new}&rvendid={$rev_id_old}&rvslots=main&format=json" ;
		return $url ;
	}

	protected function extract_revisions ( $q , $rev_id_old , $rev_id_new , $j ) {
		$ret = [] ;
		foreach ( $j->query->pages??[] AS $id => $page ) {
			foreach ( $page->revisions??[] AS $revision ) {
				if ( $revision->revid == $rev_id_old or $revision->revid == $rev_id_new ) {
					$ret["{$revision->revid}"] = json_decode($revision->slots->main->{"*"}) ;
				}
			}
		}
		return $ret ;
	}

	public function get_revisions_for_item ( $q , $rev_id_old , $rev_id_new ) {
		$url = $this->get_revisions_url ( $q , $rev_id_old , $rev_id_new ) ;
		$j = json_decode ( @file_get_contents ( $url ) ) ;
		return $this->extract_revisions ( $q , $rev_id_old , $rev_id_new , $j ) ;
	}

	protected function compare_labels_descriptions ( $rev_old , $rev_new , $key , &$ret ) {
		$v_old = $rev_old->$key ?? (object) [] ;
		$v_new = $rev_new->$key ?? (object) [] ;
		foreach ( $v_old AS $language => $v ) {
			if ( !isset($v_new->$language) ) {
				$ret[] = [ 'what'=>$key , 'change'=>'removed' , 'language'=>$language , 'text' => $v->value ] ;
			} else if ( $v->value != $v_new->$language->value ) {
				$ret[] = [ 'what'=>$key , 'change'=>'changed' , 'language'=>$language , 'text' => $v_new->$language->value ] ;
				continue ;
			}
		}
		foreach ( $v_new AS $language => $v ) {
			if ( !isset($v_old->$language) ) {
				$ret[] = [ 'what'=>$key , 'change'=>'added' , 'language'=>$language , 'text' => $v->value ] ;
			}
		}
	}

	protected function compare_aliases ( $rev_old , $rev_new , &$ret ) {
		$v_old = $rev_old->aliases ?? (object) [] ;
		$v_new = $rev_new->aliases ?? (object) [] ;
		foreach ( $v_old AS $language => $v_aliases ) {
			foreach ( $v_aliases as $v ) {
				if ( !in_array($v,$v_new->$language??[]) ) {
					$ret[] = [ 'what'=>'aliases' , 'change'=>'removed' , 'language'=>$language , 'text' => $v->value ] ;
				}
			}
		}
		foreach ( $v_new AS $language => $v_aliases ) {
			foreach ( $v_aliases as $v ) {
				if ( !in_array($v,$v_old->$language??[]) ) {
					$ret[] = [ 'what'=>'aliases' , 'change'=>'added' , 'language'=>$language , 'text' => $v->value ] ;
				}
			}
		}
	}

	protected function compare_sitelinks ( $rev_old , $rev_new , &$ret ) {
		$v_old = $rev_old->sitelinks ?? (object) [] ;
		$v_new = $rev_new->sitelinks ?? (object) [] ;
		foreach ( $v_old AS $site => $v ) {
			if ( !isset($v_new->$site) ) {
				$ret[] = [ 'what'=>'sitelinks' , 'change'=>'removed' , 'site'=>$site , 'title' => $v->title ] ;
			} else if ( $v != $v_new->$site ) {
				$ret[] = [ 'what'=>'sitelinks' , 'change'=>'changed' , 'site'=>$site , 'title' => $v_new->$site->title ] ;
				continue ;
			}
		}
		foreach ( $v_new AS $site => $v ) {
			if ( !isset($v_old->$site) ) {
				$ret[] = [ 'what'=>'sitelinks' , 'change'=>'added' , 'site'=>$site , 'title' => $v->title ] ;
			}
		}
	}

	protected function get_claim_by_id ( $claim_id , $claims ) {
		foreach ( $claims AS $claim ) {
			if ( $claim->id == $claim_id ) return $claim ;
		}
	}

	protected function compare_statements ( $rev_old , $rev_new , &$ret ) {
		$v_old = $rev_old->claims ?? (object) [] ;
		$v_new = $rev_new->claims ?? (object) [] ;
		foreach ( $v_old AS $property => $claims ) {
			foreach ( $claims AS $claim ) {
				$claim_other = $this->get_claim_by_id($claim->id,$v_new->$property??[]) ;
				if ( isset($claim_other) ) {
					if ( $claim != $claim_other ) {
						$ret[] = [ 'what'=>'statements' , 'change'=>'changed' , 'property'=>$property , 'id'=>$claim->id ] ;
					}
				} else {
					$ret[] = [ 'what'=>'statements' , 'change'=>'removed' , 'property'=>$property , 'id'=>$claim->id ] ;
				}
			}
		}
		foreach ( $v_new AS $property => $claims ) {
			foreach ( $claims AS $claim ) {
				$claim_other = $this->get_claim_by_id($claim->id,$v_old->$property??[]) ;
				if ( !isset($claim_other) ) {
					$ret[] = [ 'what'=>'statements' , 'change'=>'added' , 'property'=>$property , 'id'=>$claim->id ] ;
				}
			}
		}
	}

	public function compare_revisions ( $rev_old , $rev_new ) {
		$ret = [] ;
		$this->compare_labels_descriptions ( $rev_old , $rev_new , 'labels' , $ret ) ;
		$this->compare_labels_descriptions ( $rev_old , $rev_new , 'descriptions' , $ret ) ;
		$this->compare_aliases ( $rev_old , $rev_new , $ret ) ;
		$this->compare_statements ( $rev_old , $rev_new , $ret ) ;
		$this->compare_sitelinks ( $rev_old , $rev_new , $ret ) ;
		return $ret ;
	}

	protected function get_dbwd() {
		if ( !isset($this->dbwd) ) $this->dbwd = $this->tfc->openDBwiki ( 'wikidatawiki' ) ;
		return $this->dbwd ;
	}

	public function get_db_tool() {
		try {
			if ( !isset($this->db) ) {
				$this->db = $this->tfc->openDBtool ( 'wdrc_p' , '' , 's55078' ) ;
			}
		} catch(Exception $e) {
			sleep(1);
		}
		return $this->db ;
	}

	protected function runSQL ( $sql ) {
		if ( $this->testing ) {
			print "{$sql}\n" ;
			return ;
		}
		$db = $this->get_db_tool() ;
		$this->tfc->getSQL ( $db , $sql ) ;
	}

	public function add_one_hour($ts) {
		$ts = strtotime($ts);
		$ts += 3600;
		return date("YmdHis", $ts);
	}

	public function get_recent_changes() {
		$dbwd = $this->get_dbwd();
		$oldest = $this->get_key_value("timestamp");
		$upper_limit = $this->add_one_hour($oldest); # For speedup
		$sql = "SELECT * FROM `recentchanges` WHERE `rc_namespace`=0 AND `rc_timestamp`>='{$oldest}' AND `rc_timestamp`<='{$upper_limit}' ORDER BY `rc_timestamp`,`rc_title`,`rc_id`";
		$sql .= " LIMIT {$this->max_recent_changes}";
		$result = $this->tfc->getSQL ( $dbwd , $sql ) ;
		$ret = [] ;
		$new_items = [];
		while($o = $result->fetch_object()){
			if ( $o->rc_new==1 ) $new_items[$o->rc_title] = (object) ['q'=>$o->rc_title,'timestamp'=>$o->rc_timestamp];
			if ( !isset($ret[$o->rc_title]) ) {
				$ret[$o->rc_title] = (object) ['q'=>$o->rc_title,'new'=>$o->rc_this_oldid,'old'=>$o->rc_last_oldid,'timestamp'=>$o->rc_timestamp] ;
			} else {
				if ( $ret[$o->rc_title]->new < $o->rc_this_oldid ) $ret[$o->rc_title]->new = $o->rc_this_oldid ;
			}
		}
		$new_items = array_values($new_items);
		return (object) ["rc"=>$ret,"new"=>$new_items] ;
	}

	public function log_new_items($new_items) {
		if ( count($new_items)==0 ) return;
		$updates = [] ;
		$delete_from_deleted = [];
		foreach ( $new_items AS $o ) {
			$q = str_replace('Q','',$o->q)*1 ;
			$delete_from_deleted[] = $q ;
			$updates[] = "({$q},'{$o->timestamp}')";
		}

		# Newly created items
		$db = $this->get_db_tool();
		$sql = "REPLACE INTO `creations` (`q`,`timestamp`) VALUES ".implode(',',$updates) ;
		$this->runSQL($sql);

		# Re-created items are no longer deleted...
		$sql = "DELETE FROM `deletions` WHERE `q` IN (".implode(',',$delete_from_deleted).")";
		$this->runSQL($sql);
	}

	public function update_recent_redirects () {
		$dbwd = $this->get_dbwd() ;
		$oldest = $this->get_key_value ( 'timestamp_redirect' ) ;
		$sql = "SELECT `rc_title` AS `source`,`rd_title` AS `target`,max(`rc_timestamp`) AS `timestamp` FROM `recentchanges`,`redirect`
			WHERE `rc_namespace`=0 AND `rd_from`=`rc_cur_id` AND `rd_namespace`=0 AND `rc_timestamp`>='{$oldest}' GROUP BY `source`,`target`";
		$result = $this->tfc->getSQL ( $dbwd , $sql ) ;
		$updates = [] ;
		while($o = $result->fetch_object()) {
			$source = str_replace('Q','',$o->source);
			$target = str_replace('Q','',$o->target);
			$updates[] = "({$source},{$target},'{$o->timestamp}')";
			if ( $oldest<$o->timestamp ) $oldest = $o->timestamp;
		}
		if ( count($updates)==0 ) return ;

		$db = $this->get_db_tool();
		$sql = "REPLACE INTO `redirects` (`source`,`target`,`timestamp`) VALUES ".implode(',',$updates) ;
		$this->runSQL($sql);
		$this->set_key_value ( 'timestamp_redirect' , $oldest ) ;
	}

	public function update_recent_deletions () {
		$dbwd = $this->get_dbwd() ;
		$oldest = $this->get_key_value ( 'timestamp_deletion' ) ;
		$sql = "SELECT `log_title` AS `q`,`log_timestamp` AS `timestamp` FROM `logging` WHERE `log_type`='delete' AND `log_action`='delete' AND `log_timestamp`>='{$oldest}' AND `log_namespace`=0";
		$result = $this->tfc->getSQL ( $dbwd , $sql ) ;
		$updates = [] ;
		while($o = $result->fetch_object()) {
			$q = str_replace('Q','',$o->q);
			$updates[] = "({$q},'{$o->timestamp}')";
			if ( $oldest<$o->timestamp ) $oldest = $o->timestamp;
		}
		if ( count($updates)==0 ) return ;

		$chunks = array_chunk($updates,500);
		$updates = [];
		$db = $this->get_db_tool();
		foreach ( $chunks AS $updates ) {
			$sql = "REPLACE INTO `deletions` (`q`,`timestamp`) VALUES ".implode(',',$updates) ;
			try {
				$this->runSQL($sql);
			} catch(Exception $e) {
				print "update_recent_deletions:".$e->getMessage()." for {$sql}\n" ;
				exit(0);
			}
		}
		$this->set_key_value ( 'timestamp_deletion' , $oldest ) ;
	}

// 

	protected function get_or_create_text_id ( $text ) {
		# Initialize text cache if required
		if ( !isset($this->text_cache) ) {
			print "Loading text cache\n";
			$this->text_cache = [] ;
			$db = $this->get_db_tool() ;
			$sql = "SELECT * FROM `texts`" ;
			$result = $this->tfc->getSQL ( $db , $sql ) ;
			while($o = $result->fetch_object()) $this->text_cache[$o->value] = $o->id ;
			unset($db);
		}

		if ( isset($this->text_cache[$text]) ) return $this->text_cache[$text] ;

		# Add single text row
		print "Adding text '{$text}'\n";
		$db = $this->get_db_tool() ;
		$text_unescaped = $text ;
		$text = $db->real_escape_string ( $text ) ;
		$sql = "INSERT IGNORE INTO `texts` (`value`) VALUES ('{$text}')" ;
		$this->tfc->getSQL ( $db , $sql ) ;
		$this->text_cache[$text] = $db->insert_id ;
		return $this->text_cache[$text] ;
	}

	protected function log_statement_change ( $c ) {
		$property = preg_replace('|\D|','',$c->property ) ;
		$sql = "INSERT IGNORE INTO `statements` (`item`,`revision`,`property`,`timestamp`,`change_type`) VALUES ({$c->item},{$c->revision},{$property},{$c->timestamp},'{$c->change}')" ;
		$this->runSQL ( $sql ) ;
	}

	protected function log_sitelinks_change ( $c ) {
		$text_id = $this->get_or_create_text_id ( $c->site ) ;
		$sql = "INSERT IGNORE INTO `labels` (`item`,`revision`,`type`,`timestamp`,`change_type`,`language`) VALUES ({$c->item},{$c->revision},'{$c->what}',{$c->timestamp},'{$c->change}',{$text_id})" ;
		$this->runSQL ( $sql ) ;
	}

	protected function log_label_change ( $c ) {
		$text_id = $this->get_or_create_text_id ( $c->language ) ;
		$sql = "INSERT IGNORE INTO `labels` (`item`,`revision`,`type`,`timestamp`,`change_type`,`language`) VALUES ({$c->item},{$c->revision},'{$c->what}',{$c->timestamp},'{$c->change}',{$text_id})" ;
		$this->runSQL ( $sql ) ;
	}

	protected function log_changes ( $rc , $changes ) {
		foreach ( $changes AS $c ) {
			$c = (object) $c ;
			$c->item = preg_replace('|\D|','',$rc->q) ;
			$c->timestamp = $rc->timestamp*1 ;
			$c->revision = $rc->new ;
			if ( $c->what == 'statements' ) $this->log_statement_change($c) ;
			else if ( $c->what == 'sitelinks' ) $this->log_sitelinks_change($c) ;
			else $this->log_label_change($c) ; # Everything else
		}
	}

	public function get_key_value ( $key ) {
		$db = $this->get_db_tool() ;
		$key = $db->real_escape_string ( $key ) ;
		$sql = "SELECT * FROM `meta` WHERE `key`='{$key}'" ;
		$result = $this->tfc->getSQL ( $db , $sql ) ;
		while($o = $result->fetch_object()) return $o->value ;
	}

	protected function set_key_value ( $key , $value ) {
		$db = $this->get_db_tool() ;
		$key = $db->real_escape_string ( $key ) ;
		$value = $db->real_escape_string ( $value ) ;
		$sql = "UPDATE `meta` SET `value`='{$value}' WHERE `key`='{$key}'" ;
		$this->runSQL ( $sql ) ;
	}

	public function log_recent_changes ( $recent_changes ) {
		$timestamp = '' ;
		foreach ( $recent_changes AS $q => $rc) {
			try {
				$revisions = $this->get_revisions_for_item ( $rc->q , $rc->old , $rc->new ) ;
			} catch(Exception $e) {
				print $e->getMessage()." for {$rc->q}\n" ;
				continue ;
			}
			if ( $timestamp < $rc->timestamp ) $timestamp = $rc->timestamp ;
			$diff = $this->compare_revisions ( $revisions[$rc->old] , $revisions[$rc->new] ) ;
			$this->log_changes($rc,$diff) ;
		}
		if ( $timestamp != '' ) $this->set_key_value('timestamp',$timestamp);
	}

	public function log_recent_changes_parallel ( $recent_changes ) {
		$urls = [] ;
		foreach ( $recent_changes AS $q => $rc) {
			if ( isset($rc->old) && isset($rc->new) ) {
				$urls[$q] = $this->get_revisions_url ( $q , $rc->old , $rc->new ) ;
			}
		}
		$timestamp = '' ;
		$rc_data = $this->tfc->getMultipleURLsInParallel ( $urls ) ;
		foreach ( $rc_data AS $q => $json_text ) {
			$rc = (object) $recent_changes[$q] ;
			try {
				$j = json_decode($json_text) ;
				$revisions = $this->extract_revisions ( $q , $rc->old , $rc->new , $j ) ;
			} catch(Exception $e) {
				print $e->getMessage()." for {$rc->q}\n" ;
				continue ;
			}
			if ( $timestamp < $rc->timestamp ) $timestamp = $rc->timestamp ;
			if ( isset($revisions[$rc->old]) and isset($revisions[$rc->new]) ) {
				$diff = $this->compare_revisions ( $revisions[$rc->old] , $revisions[$rc->new] ) ;
				$this->log_changes($rc,$diff) ;
			}
		}
		if ( $timestamp != '' ) $this->set_key_value('timestamp',$timestamp);
	}

	protected function get_timestamp ( $days_ago=0 ) {
		$t = time() - $days_ago*60*60*24 ;
		return date ( 'YmdHis' , $t ) ;
	}

	public function purge_old_entries () {
		if ( !isset($this->do_purge_old_entries) ) return ;
		if ( !$this->do_purge_old_entries ) return ;
		$ts = $this->get_timestamp($this->purge_days_ago);
		foreach ( ['labels','statements','creations','deletions','redirects'] as $table ) {
			$sql = "DELETE FROM `{$table}` WHERE `timestamp`<'{$ts}' LIMIT 10000" ;
			$this->runSQL ( $sql ) ;
		}
	}

}

?>