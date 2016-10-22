<?php
require_once("include/db.php");
require_once("include/openbravo.php");
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
			<a class="csvbutton" title="Download this month as a CSV file" href="<?php echo get_datetime()->format("Y-m")?>.csv">CSV</a>
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
	$closedcash=closedcash();
	$data=closedcash_data($closedcash);
	render_data($closedcash, $data);
}
	?>
	<script src="keyboard.js"></script>
	</body>
</html>
