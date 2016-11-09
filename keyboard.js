//some simple keyboard shortcuts
(function() {
	var mappings={
		"ArrowRight": document.getElementById("next-page"),
		"ArrowLeft": document.getElementById("prev-page")
	};
	window.addEventListener("keypress", function(e) {
		if(e.target.tagName.toLowerCase()!="input" && mappings[e.key]) {
			mappings[e.key].click();
		}
	});
})();
