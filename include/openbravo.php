<?php
define("paymentOrder", ["cash", "cheque", "card", "note"]);
function paymentSort($a, $b)
{
	$l=count(paymentOrder);
	$ia=array_search($a, paymentOrder);
	$ib=array_search($b, paymentOrder);
	if($ia===false) $ia=$l;
	if($ib===false) $ib=$l;
	return $ia <=> $ib;
}
$sql=<<<EOQ
select
	any_value(TICKETS.TICKETID)     as "id",
	any_value(RECEIPTS.DATENEW)     as "date",
	any_value(PAYMENTS.TOTAL)       as "amount",
	any_value(PAYMENTS.PAYMENT)     as "payment",
	sum(TICKETLINES.PRICE * TICKETLINES.UNITS * TICKETLINES.PRICEMULTIPLIER) as "subtotal",
	sum(TICKETLINES.PRICE * TICKETLINES.UNITS * TICKETLINES.PRICEMULTIPLIER * (1 + TAXES.RATE)) as "total",
	sum(if(TAXES.NAME="Sales Tax", TICKETLINES.PRICE * TICKETLINES.UNITS * TICKETLINES.PRICEMULTIPLIER * TAXES.RATE, 0)) as "sales_tax",
	sum(if(TAXES.NAME="Tax Exempt", TICKETLINES.PRICE * TICKETLINES.UNITS * TICKETLINES.PRICEMULTIPLIER * TAXES.RATE, 0)) as "pst_exempt"

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
	CLOSEDCASH.MONEY=?

group by
	TICKETS.TICKETID,
	PAYMENTS.ID
EOQ;
function get_datetime($str=null) {
	static $now=false;
	if($now===false) $now=new DateTime($str); #this way we don't have to worry if execution takes too long
	if(isset($_GET["date"]) && (new DateTime($_GET["date"]))) {
		$d=new DateTime($_GET["date"]);
	}
	else {
		$d=$now;
	}
	$hrs=12;
	$min=0;
	if(
		isset($_GET["time"]) &&
		preg_match('/^(\d{1,2})[:.](\d{2})$/', $_GET["time"], $matches) &&
		$matches[1] >= 0 &&
		$matches[1] <= 23 &&
		$matches[2]>=0 &&
		$matches[2]<=59
	) {
		$hrs=$matches[1];
		$min=$matches[2];
	}
	return $d->setTime($hrs, $min);
}
function closedcash(DateTime $date=null) {
	static $cache=[]; #setup cache
	if($date===null) $date=get_datetime(); #if no date is given, get one yourself
	$key=$date->format("Y-m-d H:i:s"); #make key for saving and retrieving cache entry
	if(isset($cache[$key])) return $cache["$key"]; #early exit if cache entry exists
	$result=query(
		"select MONEY as id, DATESTART as start, DATEEND as end from CLOSEDCASH where ? between DATESTART and DATEEND",
		["s", $date->format("Y-m-d H:i:s")]
	)->fetch_assoc();
	if($result) {
		return $cache[$key]=["id"=>$result["id"], "start"=>new DateTime($result["start"]), "end"=>new DateTime($result["end"])];
	}
	else {
		$result=query(
			"select DATEEND as end from CLOSEDCASH where DATEEND in (SELECT max(DATEEND) from CLOSEDCASH)", []
		)->fetch_assoc();
		return $cache[$key]=["start"=>new DateTime($result["end"])];
	}
}
function closedcash_sameday(DateTime $date=null) {
	$closedcash=closedcash($date);
	return ($date->format("Y-m-d") == $closedcash["end"]->format("Y-m-d"))? $closedcash : null;
}
function process_results($results) {
	global $config;
	$data=[];
	$closedcash=[];
	$totals=[
		"sales_tax"=>0,
		"pst_exempt"=>0,
		"subtotal"=>0,
		"sales"=>0,
		"payments"=>0,
	];
	$paymentTotals=[];
	while($result=$results->fetch_assoc()) {
		$id=$result["id"];
		if(!isset($data[$id])) {
			$data[$id]=["payments"=>[], "total_payments"=>0, "total_sales"=>0];
			$totals["sales"]+=$result["total"];
			$totals["sales_tax"]+=$result["sales_tax"];
			$totals["pst_exempt"]+=$result["pst_exempt"];
			$totals["subtotal"]+=$result["subtotal"];
		}
		$data[$id]["date"]=$result["date"];
		$data[$id]["total_sales"]=$result["total"];
		$payment=$result["payment"];
		preg_match('/^(.+?)(out|refund)?$/', $result["payment"], $matches);
		$payment=$matches[1];
		if($payment=="paper" || $payment=="paperin") {
			$payment="note";
		}
		elseif($payment=="magcard") {
			$payment="card";
		}
		if(!isset($paymentTotals[$payment])) {
			$paymentTotals[$payment]=0;
		}
		$paymentTotals[$payment]+=$result["amount"];
		$type=isset($matches[2])?"refund":"sale";
		array_push($data[$id]["payments"], [$payment, $result["amount"]]);
		$data[$id]["type"]=$type;
		$totals["payments"]+=$result["amount"];
		$data[$id]["total_payments"]+=$result["amount"];
	}
	foreach($data as $item) {
		usort($item["payments"], function($a, $b){
			return paymentSort($a[0], $b[0]);
		});
	}
	uksort($paymentTotals, paymentSort);
	$gstRate=$config["gst"]/100;
	$pstRate=$config["pst"]/100;
	$totals["pst"]=($pstRate/($pstRate+$gstRate))*$totals["sales_tax"];
	$totals["gst"]=($gstRate/($pstRate+$gstRate))*$totals["sales_tax"]+$totals["pst_exempt"];
	$totals["taxes"]=$totals["pst"]+$totals["gst"];
	return ["totals"=>$totals, "paymentTotals"=>$paymentTotals, "data"=>$data, "closedcash"=>$closedcash];
}
function day_data(DateTime $date=null, $coerce=true) {
	if($coerce) {
		return closedcash_data(closedcash($date));
	}
	else {
		return closedcash_data(closedcash_sameday($date));
	}
}
function closedcash_data($closedcash) {
	global $sql;
	return process_results(query($sql, ["s", $closedcash["id"]]));
}
function get_payment_total($data, $type) {
	return isset($data["paymentTotals"][$type]) ? $data["paymentTotals"][$type] : 0;
}
