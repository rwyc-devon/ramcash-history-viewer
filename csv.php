<?php
header("Content-type: text/plain");
require_once("include/db.php");
require_once("include/openbravo.php");
require_once("config.php");
function csvnum($num) {
	return $num? round($num, 2): "";
}
function csv_line($data) {
	if($data) {
		$fields=[
			csvnum(get_payment_total($data, "cash") + get_payment_total($data, "cheque")),
			csvnum(get_payment_total($data, "note")),
			csvnum($data["totals"]["subtotal"]),
			csvnum($data["totals"]["pst"]),
			csvnum($data["totals"]["gst"]),
		];
	}
	else {
		$fields=["", "", "", "", ""];
	}
	return "\"" . join("\", \"", $fields) . "\"\n";
}
function csv_line_from_date($date) {
	return csv_line(day_data($date, false));
}
$date=get_datetime()->modify("first day of this month")->setTime(12,0);
$end=(clone $date)->modify("first day of next month");
echo "\"Day\", \"Cash\", \"Note\", \"Sales\", \"PST\", \"GST\"\n";
while($date<$end) {
	$day=$date->format("Y-m-d");
	echo "\"$day\", " . csv_line_from_date($date);
	$date->modify("tomorrow")->setTime(12,0);
}
