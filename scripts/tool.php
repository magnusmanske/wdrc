#!/usr/bin/php
<?PHP

require_once ( 'WDRC.php' ) ;

$tests = [
	# Labels
	[ 'q'=>'Q25323','new'=>54078195,'old'=>49171822,'expected'=>'[{"what":"labels","change":"added","language":"it","text":"Bion 1"}]' ],
	[ 'q'=>'Q179295','new'=>1672076593,'old'=>1666409562,'expected'=>'[{"what":"labels","change":"changed","language":"ru","text":"\u043a\u043e\u043d\u0443\u0440\u0431\u0430\u0446\u0438\u044f \u0414\u0430\u043b\u043b\u0430\u0441 \u2014 \u0424\u043e\u0440\u0442-\u0423\u044d\u0440\u0442"}]' ],

	# Descriptions
	[ 'q'=>'Q25323','new'=>54078223,'old'=>54078195,'expected'=>'[{"what":"descriptions","change":"added","language":"it","text":"satellite artificiale russo"}]' ],

	# Aliases
	[ 'q'=>'Q25323','new'=>1672036382,'old'=>1650099482,'expected'=>'[{"what":"aliases","change":"added","language":"en","text":"Kosmos 605"}]' ],

	# Sitelinks
	[ 'q'=>'Q25323','new'=>34158731,'old'=>33314372,'expected'=>'[{"what":"sitelinks","change":"added","site":"ptwiki","title":"Bion 1"}]' ],
	[ 'q'=>'Q14020337','new'=>1672076490,'old'=>1672063272,'expected'=>'[{"what":"sitelinks","change":"removed","site":"plwiki","title":"Kategoria:Filmy z serii Ksi\u0119ga d\u017cungli"}]' ],

	# Statements
	[ 'q'=>'Q25323','new'=>971733012,'old'=>971732523,'expected'=>'[{"what":"statements","change":"added","property":"P361","id":"Q25323$c5a2af93-4545-e90d-bdcf-a1f2a4ce459e"}]' ],
	[ 'q'=>'Q25323','new'=>971732523,'old'=>971732202,'expected'=>'[{"what":"statements","change":"changed","property":"P31","id":"q25323$E880BDFB-A424-4EFF-905D-012684D5E973"}]' ],
	[ 'q'=>'Q25323','new'=>1560191383,'old'=>1560191286,'expected'=>'[{"what":"statements","change":"removed","property":"P155","id":"Q25323$d8470235-4307-e4a6-bae7-3bdb653b2aaa"}]' ],
] ;

$wdrc = new WDRC ;

function run_tests () {
	global $wdrc , $tests ;
	foreach ( $tests AS $test_num => $test ) {
		$test_num++ ;
		$test = (object) $test ;
		$revisions = $wdrc->get_revisions_for_item ( $test->q , $test->old , $test->new ) ;
		$diff = $wdrc->compare_revisions ( $revisions[$test->old] , $revisions[$test->new] ) ;
		if ( json_encode($diff) == $test->expected ) {
			print "Test {$test_num} OK\n" ;
			continue ;
		}
		print "Test {$test_num} FAILED\n" ;
		print "Received: " . json_encode($diff) . "\n" ;
		print "Expected: " . $test->expected . "\n" ;
	}
}

$command = $argv[1]??'' ;

if ( $command == 'oneoff' ) {
	$wdrc->update_recent_deletions();
	// $wdrc->purge_old_entries();
	exit(0);
}

if ( $command == 'tests' ) {
	run_tests() ;
	exit(0);
}

if ( $command == 'run' ) {
	while ( 1 ) {
		$wdrc->update_recent_redirects();
		$wdrc->update_recent_deletions();
		$recent_changes = $wdrc->get_recent_changes();
		$wdrc->log_recent_changes_parallel ( $recent_changes->rc ) ;
		$wdrc->log_new_items($recent_changes->new);
		// $wdrc->purge_old_entries();
	}
	exit(0);
}


$wdrc->testing = true ;
if ( 1 ) {
	$recent_changes = $wdrc->get_recent_changes();
	#$wdrc->log_recent_changes ( $recent_changes ) ;
	$wdrc->log_recent_changes_parallel ( $recent_changes ) ;
} else {
	$q = 'Q179295' ;
	$r_new = 1672076593 ;
	$r_old = 1666409562 ;
	$revisions = $wdrc->get_revisions_for_item ( $q , $r_old , $r_new ) ;
	$diff = $wdrc->compare_revisions ( $revisions[$r_old] , $revisions[$r_new] ) ;
	print_r ( json_encode($diff) ) ;
	print_r ( $revisions ) ;
}

?>