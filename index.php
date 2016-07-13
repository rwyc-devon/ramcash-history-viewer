<!DOCTYPE html><?php #TODO: Filter by cash closed instead of date?>
<html>
	<head>
		<title>Ramcash History Viewer</title>
		<link rel="stylesheet" href="style.css">
	</head>
	<body>
		<h1>Ramcash History Viewer</h1>
		<form method="GET">
			<label for="datein">Date</label><input id="datein" name="date" placeholder="yyyy-mm-dd" type="date" value="<?php echo validate_date()?>"></input>
			<input type="submit"></input>
		</form>
<?php
require_once("config.php");
$sql=<<<EOQ
select
	any_value(TICKETS.TICKETID)          as "id",
	any_value(RECEIPTS.DATENEW)          as "date",
	any_value(format(PAYMENTS.TOTAL, 2)) as "amount",
	any_value(PAYMENTS.PAYMENT)          as "payment",
	format(sum(TICKETLINES.PRICE * TICKETLINES.UNITS * TICKETLINES.PRICEMULTIPLIER * (1 + TAXES.RATE)), 2) as "total"

from RECEIPTS

left join PAYMENTS on
	PAYMENTS.RECEIPT=RECEIPTS.ID

left join TICKETS on
	TICKETS.ID=RECEIPTS.ID

left join CLOSEDCASH on
	RECEIPTS.MONEY=CLOSEDCASH.MONEY

left join TICKETLINES on
	TICKETLINES.TICKET=TICKETS.ID

left join TAXES on
	TICKETLINES.TAXID=TAXES.ID

where
	date_add(?, interval 12 hour) between CLOSEDCASH.DATESTART and CLOSEDCASH.DATEEND

group by
	RECEIPTS.DATENEW,
	PAYMENTS.PAYMENT
EOQ;
function fail($action, $errno, $err) {
	echo "<div class='error'>$action failed (Error $errno: <code>$err</code>)</div>\n";
}
function validate_date() {
	return (preg_match("/^(\d{4})-(\d{2})-(\d{2})$/", $_GET["date"], $m) && checkdate($m[2], $m[3], $m[1])) ? $_GET["date"] : "";
}
function tag($name, $attributes = false, ...$contents)
{
	$attributeString="";
	if(isset($attributes) && $attributes) {
		foreach($attributes as $key=>$value) {
			if($value === true)
				$attributeString.=" $key"; #true boolean attributes don't need a value string
			elseif($value === false)
				$attributeString.=" $key='false'"; #but false ones do
			elseif($value)
				$attributeString.=" $key='$value'"; #string ones definitely do
			#and "falsey", non-false values will just get left out. if you want it explicitly set false, explicitly set it false!
		}
	}
	$content="";
	foreach($contents ?? [] as $c) {
		$content.=$c ?? ""; #this way undefined items don't throw an error like they would with implode
	}
	return "<$name$attributeString>$content</$name>";
}
if($date=validate_date()) {
	$db=new mysqli($config["host"], $config["user"], $config["password"], $config["db"]);
	if($db->connect_errno) {
		fail("Connection", $db->connect_errno, $db->connect_error);
	}
	elseif(!($q = $db->prepare($sql))) {
		fail("Prepare", $db->errno, $db->error);
	}
	elseif(!$q->bind_param("s", $date)) {#, $date)) {
		fail("Parameter Binding", $q->errno, $q->error);
	}
	elseif(!$q->execute()) {
		fail("Execute", $q->errno, $q->error);
	}
	elseif(!($results = $q->get_result())) {
		fail("Getting Results", $q->errno, $q->error);
	}
	else {
		$prev="";
		echo "\t\t<table>\n\t\t\t<thead>\n\t\t\t\t<tr><th></th><th>#</th><th>Date</th><th>Payment</th><th>Method</th><th>Total</th></tr>\n\t\t\t</thead>\n\t\t\t<tbody>\n";
		while($result=$results->fetch_assoc()) {
			$id=$checkbox="";
			if($result["id"]!=$prev) {
				if($prev) {
					echo "\t\t\t</tbody>\n\t\t\t<tbody>\n";
				}
				$checkbox="<input type='checkbox'>";
				$id=$result["id"];
				$total=$result["total"];
			}
			echo "\t\t\t\t<tr><td><input type='checkbox'></td><td>$id</td><td>${result["date"]}</td><td>${result["amount"]}</td><td>${result["payment"]}</td><td>$total</td></tr>\n";
			$prev=$result["id"];
		}
		echo "\t\t\t</tbody>\n\t\t</table>\n";
	}
}
	?>
	</body>
</html>
