window.addEventListener('DOMContentLoaded', function() {
    var radios = document.forms["mepr_alepay_payment_form"].elements["alepay_payment_type"];

    for (radio in radios) {
        radios[radio].onclick = function() {
            console.log(this.value);
            if (this.value == 'international' || this.value == 'one_click_payment') {
                document.getElementById("card-link-container").style.display = "block";
                // document.getElementById("is-card-link").style.display = "block";
            } else {
                document.getElementById("card-link-container").style.display = "none";
                // document.getElementById("is-card-link").style.display = "none";
            }
        }
    }

});