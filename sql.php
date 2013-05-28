<?php

/*****************************************
*                                        *
* Class for handing MySQL queries        *
*                                        *
* Version 0.9.11                         *
*                                        *
* Copyright (c) 09.2005                  *
*                                        *
* Nikolay Mihaylov nmmm@nmmm.nu          *
*                                        *
* idea and first lines from:             *
* Diyan Chuburov, no1knows.me@gmail.com  *
*                                        *
*****************************************/

/*
*   Version history:
*       0.9.3	2005-10-21	Nikolay		Added support for mysql_insert_id()
*
*       0.9.2	2005-10-15	Dido		Added fetchFields() function - return table fields as an array
*
*	0.9.4	2005-11-03	Nikolay		Added support for ROLLBACK when error occured. Added $SQL_EXIT_WHEN_ERROR
*
*	0.9.5	2005-11-07	Nikolay		Added support array() with SQL statements, also executing of SQL may not be imediate
*
*	0.9.6	2005-11-07	Nikolay		Added Additional object for handing transactions.
*
*	0.9.7	2005-11-07	Nikolay		Added method success() to TransactionSQL object.
*
*	0.9.8	2005-12-03	Nikolay		fetchFields() using "for"
*
*	0.9.9	2006-02-11	Nikolay		fixing SQL array() with PageSQL() see v.0.9.5
*
*	0.9.10	2006-02-16	Nikolay		fetchArrayAll with primary key
*
*	0.9.11	2006-05-29	Nikolay		Minor TransactionSQL->rollback() fix
*
*	0.9.12  2011-06-XX	Nikolay		CacheSQL rewritten
*
*	0.9.13  2011-06-21	Nikolay		Better error message (updated 2011-06-30)
*
*	0.9.14  2011-06-27	Nikolay		/dev/shm FileCacheSQL
*
*	0.9.15  2011-07-01	Nikolay		PageSQL now supports number of all records as int
*
*	0.9.16  2011-07-11	Nikolay		fetchArray() now do strip slashes
*
*/

//Class that handle Abstract SQL

$SQL_SHOW_WHEN_ERROR     = false;
$SQL_EXIT_WHEN_ERROR     = true;
//$SQL_ROLLBACK_WHEN_ERROR = false;

class BasicSQL{
	var $sqlResult;

	//mysql_errno
	var $errorNum;

	//mysql_error
	var $errorText;

	//SQL query
	var $sql_query;

	function BasicSQL($sql, $exec_now = true){
		if ( is_array($sql) ){
			$this->sql_query = $sql;
		}else{
			$this->sql_query = array( $sql );
		}


		if ($exec_now)
			$this->executeSQL();
	}

	function  displayError($sql){
		echo"
			<div style='border: dashed 2px red; padding: 5px; align:left;'>MySQL ERROR {$this->errorText} on:<!--
			--><pre style='border: dashed 2px blue; padding: 5px;'>$sql</pre><!--
			--></div>
		";
	}

	function  displaySQL(){
		echo"<div><pre style='border: dashed 2px blue; padding: 5px; text-align:left;'>";

		print_r( $this->sql_query );

		echo "</pre></div>";
	}

	function  executeSQL(){
		foreach( $this->sql_query as $sql ){
			$this->sqlResult = mysql_query( $sql );

			$this->errorNum  = mysql_errno();
			$this->errorText = mysql_error();

			if (! $this->sqlResult){
				/*
				global $SQL_ROLLBACK_WHEN_ERROR;

				if ( $SQL_ROLLBACK_WHEN_ERROR )
					mysql_query("rollback");
				*/

				global $SQL_SHOW_WHEN_ERROR;

				if ( $SQL_SHOW_WHEN_ERROR )
					$this->displayError($sql);

				global $SQL_EXIT_WHEN_ERROR;

				if ( $SQL_EXIT_WHEN_ERROR ){
					$this->displayError($sql);
					exit;
				}

				break;
			}
		}
	}
}

//
// ***************************************************************************
//

//Class that handle Exec SQL (UPDATE/DELETE/INSERT)

class ExecSQL extends BasicSQL{
	var $affectedRows;
	var $insertID;

	function ExecSQL($sql, $exec_now = true){
		$this->affectedRows = 0;
		$this->insertID     = 0;

		parent::BasicSQL($sql, $exec_now);
	}

	function executeSQL(){
		parent::executeSQL();

		// consider revision:
		// mysql_affected_rows($this->sqlResult)
		// not work as expected.

		$this->affectedRows = mysql_affected_rows();
		$this->insertID     = mysql_insert_id();
	}
}

//
// ***************************************************************************
//

//Class that handle Select SQL (SELECT * FROM TABLE)

class SelectSQL extends BasicSQL {
	var $numRows;

	function SelectSQL($sql, $exec_now = true){
		parent::BasicSQL($sql, $exec_now);

		if ($exec_now)
			$this->numRows = mysql_num_rows($this->sqlResult);
	}

	function fetchSingle(){
		$a = $this->fetchArray();

		return $a[0];
	}

	function fetchArray(){
		// using mysql_fetch_assoc - 11.07.2011
		//$res_arr = mysql_fetch_assoc($this->sqlResult);

		// and going back same day :D
		$res_arr = mysql_fetch_array($this->sqlResult);

		if (is_array($res_arr)){
			foreach( array_keys($res_arr) as $key ) {
				$res_arr[$key] = stripslashes( $res_arr[$key] );
			}
		}

		return $res_arr;
	}

	function fetchArrayAll($key = NULL){
		$resultArray = array();

		while($resultRow = $this->fetchArray()) {
			if ($key)
				$resultArray[ $resultRow[ $key ] ] = $resultRow;
			else
				$resultArray[] = $resultRow;
		}

		return $resultArray;
	}

	function fetchFields(){
		$_array = array(); // return

		for ($i = 0; $i < mysql_num_fields($this->sqlResult); $i++){
			$fieldObject = mysql_fetch_field($this->sqlResult, $i);
			$_array[] = $fieldObject->name;
		}

		return $_array;
	}
}

//
// ***************************************************************************
//

//Class that handle Select SQL with pages (SELECT * FROM TABLE LIMIT 1,1)

class PageSQL extends SelectSQL{
	var $numRowsAll;

	var $rowsPerPage;

	var $totalPage;
	var $thisPage;
	var $prevPage;
	var $nextPage;

	function PageSQL($sql, $rowsPerPage=10, $thisPage=1, $countsql=NULL){
		$this->rowsPerPage = $rowsPerPage;
		if ($countsql == NULL || $countsql === 0){
			$this->numRowsAll = $this->numRows;

	//	}else if ($countsql === (int) $countsql){
		}else if ( (int) $countsql ){
			$this->numRowsAll = $countsql;

		}else {
			$csql = new SelectSQL($countsql);
			$this->numRowsAll = $csql->fetchSingle();
			$csql = NULL;

		}

		$this->totalPage = $this->numRowsAll / $this->rowsPerPage;

		if((int)$this->totalPage < $this->totalPage) {
			$this->totalPage = (int)$this->totalPage + 1;
		}

		$this->thisPage = $thisPage >= 1 && $thisPage <= $this->totalPage ? $thisPage : 1;

		$this->prevPage = $this->thisPage > 0                ? $this->thisPage - 1 : 0;
		$this->nextPage = $this->thisPage < $this->totalPage ? $this->thisPage + 1 : 0;

		if ( ! is_array($sql) )
			$sql = array( $sql );

		if ( count($sql) ){
			for($i = 0; $i < count($sql); $i++){
				$singlesql = $sql[$i];

				$singlesql = sprintf($singlesql, ($this->thisPage - 1) * $this->rowsPerPage, $this->rowsPerPage);

				// dont work if there is % in the
				// sql statement; example: LIKE 'B%'
				// %% = %
				$singlesql = str_replace("(_)", "%", $singlesql);

				$sql[$i] = $singlesql;
			}
		}

		//print_r($sql);

		parent::SelectSQL($sql, true);
	}

	function fetchPager($limit = 0){
		$pager = array(
			"total"     => $this->totalPage,
			"this"      => $this->thisPage,
			"prev"      => $this->prevPage,
			"next"      => $this->nextPage,

			"first"     => 1,
			"last"      => $this->totalPage,

			"all"       => $this->totalPage,
			"previous"  => $this->prevPage,
			"page"      => $this->thisPage
		);

		if ($limit > 0){
			$pager["first"] = $this->thisPage - $limit < 1 ?
					1 :
					$this->thisPage - $limit;

			$pager["last" ] = $this->thisPage + $limit > $this->totalPage ?
					$this->totalPage :
					$this->thisPage + $limit;
		}

		//print_r($pager);
		return $pager;
	}
}

//
// ***************************************************************************
//

//
// ***************************************************************************
//

//
// ***************************************************************************
//

// Class that handle transactions and process the rollback.

class TransactionSQL{
	//mysql_errno
	var $rollback;

	var $show_debug;

	// READ UNCOMMITTED = DIRTY READ
	function TransactionSQL($isolation = "READ UNCOMMITTED", $show_debug = false){
		$this->rollback = false;

		$this->show_debug = $show_debug;

		mysql_query("BEGIN");

		if ($isolation)
			mysql_query("set TRANSACTION ISOLATION LEVEL $isolation");
	}

	// execute ExecSQL->executeSQL(), handle result, bollback if needed.
	function executeSQL(&$execSQL){
		if (! $this->rollback ){
			$execSQL->executeSQL();

			if ($this->show_debug){
				echo "<pre>";
				print_r($execSQL->sql_query);
				echo "</pre> result:" . $execSQL->errorNum . ":" . $this->rollback . "<hr />";
			}

			if ($execSQL->errorNum){
				$this->rollback = true;
				mysql_query("rollback");

				if ($this->show_debug){
					echo "ROLLBACK";
				}
			}
		}
	}

	function commit(){
		if (! $this->rollback)
			mysql_query("commit");
	}

	function rollback(){
		if (! $this->rollback)
			mysql_query("rollback");

		$this->rollback = true;
	}

	function success(){
		return $this->rollback ? false : true;
	}
}

