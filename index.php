<!DOCTYPE html>
<html>
	<head>
		<title>Ramcash History Viewer</title>
		<link rel="stylesheet" href="style.css">
	</head>
	<body>
		<h1>Ramcash History Viewer</h1>
		<form method="GET">
			<a rel="prev" href="?date=<?php echo next_date()?>"></a>
			<label for="datein">Date</label><input id="datein" name="date" placeholder="yyyy-mm-dd" type="date" value="<?php echo validate_date()?>"></input>
			<input id="time" name="time" placeholder="hh:mm" type="time" value="<?php echo validate_time()?validate_time()[0]:"12:00"?>"></input>
			<input type="submit"></input>
			<a rel="next" href="?date=<?php echo prev_date()?>"></a>
		</form>
<?php
require_once("include/db.php");
require_once("config.php");
$sql=<<<EOQ
select
	any_value(CLOSEDCASH.DATESTART) as "start",
	any_value(CLOSEDCASH.DATEEND)   as "end",
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
	date_add(date_add(?, interval ? hour), interval ? minute) between CLOSEDCASH.DATESTART and CLOSEDCASH.DATEEND

group by
	RECEIPTS.DATENEW,
	PAYMENTS.ID
EOQ;
function validate_time() {
	return (preg_match('/^(\d{1,2})[:.](\d{2})$/', $_GET["time"], $m) && $m[1] >= 0 && $m[1] <= 23 && m[2]>=0 && m[2]<=59) ? $m : false;
}
function validate_date() {
	return (preg_match('/^(\d{4})[-\/.](\d{2})[-\/.](\d{2})$/', $_GET["date"], $m) && checkdate($m[2], $m[3], $m[1])) ? $_GET["date"] : "";
}
function date_range() {

}
function prev_date() {
	if(!($date=validate_date())) return "";
	$interval=new DateInterval("P1D");
	$datetime=new DateTime($date);
	$newdate=$datetime->sub($interval);
	return $newdate->format("Y-m-d");
}
function next_date() {
	if(!($date=validate_date())) return "";
	$interval=new DateInterval("P1D");
	$datetime=new DateTime($date);
	$newdate=$datetime->add($interval);
	return $newdate->format("Y-m-d");
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
	while($result=$results->fetch_assoc()) {
		$id=$result["id"];
		if(!isset($closedcash["start"])) $closedcash["start"]=$result["start"];
		if(!isset($closedcash["end"])) $closedcash["end"]=$result["end"];
		if(!isset($data[$id])) {
			$data[$id]=["payments"=>[], "total"=>0];
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
		$type=isset($matches[2])?"refund":"sale";
		array_push($data[$id]["payments"], [$payment, $result["amount"]]);
		$data[$id]["type"]=$type;
		$totals["payments"]+=$result["amount"];
		$data[$id]["total_payments"]+=$result["amount"];
	}
	foreach($data as $item) {
		ksort($item["payments"]);
	}
	$gstRate=$config["gst"]/100;
	$pstRate=$config["pst"]/100;
	$totals["pst"]=($pstRate/($pstRate+$gstRate))*$totals["sales_tax"];
	$totals["gst"]=($gstRate/($pstRate+$gstRate))*$totals["sales_tax"]+$totals["pst_exempt"];
	$totals["taxes"]=$totals["pst"]+$totals["gst"];
	return ["totals"=>$totals, "data"=>$data, "closedcash"=>$closedcash];
}
function render_data($data) {
	echo tag("ul", ["id"=>"closedcash"],
		tag("li", ["id"=>"start"], $data["closedcash"]["start"]),
		tag("li", ["id"=>"end"], $data["closedcash"]["end"])
	);
	echo tag("ul", ["id"=>"totals", "class"=>(number_format($data["totals"]["payments"],2)==number_format($data["totals"]["sales"],2)?NULL:"warning")],
		tag("li", ["id"=>"subtotal"], number_format($data["totals"]["subtotal"], 2)),
		tag("li", ["id"=>"taxes"],
			tag("ul", false, 
				tag("li", ["id"=>"pst"],      number_format($data["totals"]["pst"],      2)),
				tag("li", ["id"=>"gst"],      number_format($data["totals"]["gst"],      2)),
				tag("li", ["class"=>"total"],    number_format($data["totals"]["taxes"],    2))
			)
		),
		tag("li", ["class"=>"total"],    number_format($data["totals"]["sales"],    2)),
		tag("li", ["class"=>"payments"],    number_format($data["totals"]["payments"],    2))
	);
	foreach($data["data"] as $id=>$item) {
		$diff=abs(round($item["total_sales"],2)-round($item["total_payments"],2));
		$class=$diff==0? "": "warning"; #warn if totals don't balance
		$class.=" ".$item["type"];
		$payments="";
		foreach($item["payments"] as $p) {
			$method=$p[0];
			$payment=$p[1];
			$payments.=tag("li", ["class"=>"payment"],
				tag("span", ["class"=>"method method-$method"], $method),
				tag("span", ["class"=>"amount"], number_format($payment, 2))
			);
		}
		$payments.=tag("li", ["class"=>"spacer"]);
		$payments.=tag("li", ["class"=>"total"],
			number_format($item["total_payments"], 2)
		);
		$payments.=tag("li", ["class"=>"sales"],
			number_format($item["total_sales"], 2)
		);
		if($diff!=0) {
			$payments.=tag("li", ["class"=>"difference"],
				number_format($diff,2)
			);
		}
		echo tag("label", false, tag("section", ["id"=>"receipt_$id", "class"=>$class], 
			tag("input", ["type"=>"checkbox"]), 
			tag("header", false, 
				tag("span", ["class"=>"id"], $id),
				tag("time", false, $item["date"])
			),
			tag("ul", ["class"=>"payments"], $payments)
		));
	}
}
if(($date=validate_date())) {
	$time=validate_time() ?: ["12:00", 12, 0];
	render_data(process_results(query($sql, ["sii", $date, $time[1], $time[2]])));
}
	?>
	</body>
</html>
