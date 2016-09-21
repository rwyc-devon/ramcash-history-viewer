<?php
require_once("include/db.php");
require_once("config.php");
?><!DOCTYPE html>
<html>
	<head>
		<title>Ramcash History Viewer</title>
		<link rel="stylesheet" href="style.css">
	</head>
	<body>
		<h1>Ramcash History Viewer</h1>
		<form method="GET">
			<a id="prev-page" rel="prev" <?php
if(closedcash())
	echo "href='" . closedcash()["start"]->sub(new DateInterval("PT1M"))->format('?\\d\\a\\t\\e=Y-m-d&\\t\\i\\m\\e=H:i') . "'";
			?>></a>
			<label for="datein">Date</label><input id="datein" name="date" placeholder="yyyy-mm-dd" type="date" value="<?php echo get_datetime()->format("Y-m-d")?>"></input>
			<input id="time" name="time" placeholder="hh:mm" type="time" value="<?php echo get_datetime()->format("H:i")?>"></input>
			<input type="submit"></input>
			<a id="next-page" rel="next" <?php
if(closedcash() && isset(closedcash()["end"])) {
	$today_end=closedcash()["end"];
	if($today_end) {
		$tomorrow=closedcash($today_end->add(new DateInterval("PT1M")));
		if($tomorrow && isset($tomorrow["end"])) {
			$tomorrow_end=$tomorrow["end"]->sub(new DateInterval("PT1M"));
			echo "href='".$tomorrow_end->format('?\\d\\a\\t\\e=Y-m-d&\\t\\i\\m\\e=H:i')."'";
		}
	}
}
			?>></a>
		</form>
<?php
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
	RECEIPTS.DATENEW,
	PAYMENTS.ID
EOQ;
#TODO: at some point we can switch to CLOSEDCASH id, saving on a bunch of probably expensive date logic
function get_datetime() {
	static $now=false;
	if($now===false) $now=new DateTime(); #this way we don't have to worry if execution takes too long
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
		ksort($item["payments"]);
	}
	$gstRate=$config["gst"]/100;
	$pstRate=$config["pst"]/100;
	$totals["pst"]=($pstRate/($pstRate+$gstRate))*$totals["sales_tax"];
	$totals["gst"]=($gstRate/($pstRate+$gstRate))*$totals["sales_tax"]+$totals["pst_exempt"];
	$totals["taxes"]=$totals["pst"]+$totals["gst"];
	return ["totals"=>$totals, "paymentTotals"=>$paymentTotals, "data"=>$data, "closedcash"=>$closedcash];
}
function render_data($closedcash, $data) {
	echo tag("ul", ["class"=>"bar", "id"=>"closedcash"],
		tag("li", ["id"=>"start"], $closedcash["start"]->format("M jS H:m")),
		tag("li", ["id"=>"end"], $closedcash["end"]->format("M jS H:m"))
	);
	$payments="";
	foreach($data["paymentTotals"] as $name=>$amount) {
		$payments.=tag("li", ["class"=>"payment"],
			tag("span", ["class"=>"method method-$name"], $name),
			tag("span", ["class"=>"amount"], number_format($amount, 2))
		);
	}
	echo tag("ul", ["id"=>"totals", "class"=>"bar ".(number_format($data["totals"]["payments"],2)==number_format($data["totals"]["sales"],2)?NULL:"warning")],
		tag("li", ["id"=>"subtotal"], number_format($data["totals"]["subtotal"], 2)),
		tag("li", ["id"=>"taxes"],
			tag("ul", false, 
				tag("li", ["id"=>"pst"],      number_format($data["totals"]["pst"],      2)),
				tag("li", ["id"=>"gst"],      number_format($data["totals"]["gst"],      2)),
				tag("li", ["class"=>"total"],    number_format($data["totals"]["taxes"],    2))
			)
		),
		tag("li", ["class"=>"total"],    number_format($data["totals"]["sales"],    2)),
		tag("li",
			["id"=>"payments"],
			tag("ul", false,
				$payments,
				tag("li", ["class"=>"total"], number_format($data["totals"]["payments"], 2))
			)
		)
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

if(isset((closedcash()["id"]))) {
	render_data(closedcash(), process_results(query($sql, ["s", closedcash()["id"]])));
}
	?>
	<script src="keyboard.js"></script>
	</body>
</html>
