<?php
function query($query, $params) {
	global $config;
	static $db;
	static $queries=[];
	$bindparams=[];
	if(!$db)
		$db=new mysqli($config["host"], $config["user"], $config["password"], $config["db"]);
	if($queries[$query])
		$queries[$query]->reset();
	else
		$queries[$query] = $db->prepare($query);
	$q=$queries[$query];
	foreach($params as $key => $param) $bindparams[$key] = &$params[$key];
	call_user_func_array(array($q, "bind_param"), $bindparams);
	$q->execute();
	return $q->get_result();
}
?>
