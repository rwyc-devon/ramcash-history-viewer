//some simple keyboard shortcuts
(function() {
	var mappings={
		"ArrowRight": document.getElementById("next-page"),
		"ArrowLeft": document.getElementById("prev-page")
	};
	console.log(mappings);
	window.addEventListener("keypress", function(e) {
		console.log(e.key);
		console.log(mappings[e.key]);
		if(mappings[e.key]) {
			mappings[e.key].click();
		}
	});
})();
