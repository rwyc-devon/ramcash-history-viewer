Ramcash-history-viewer
======================

This may be of limited use outside of our specific openbravopos version, but it's a very pretty history viewer and it makes a lot of bookkeeping stuff a lot more convenient. It also relies on some specific configurations relating to taxes. Specifically useful in Canadian provinces that have separate PST and GST. You may need to edit the query a bit to match whatever you named your tax types.

Specifically, it assumes you have tax types named `Sales Tax` for both PST and GST, and `Tax Exempt` for GST-only stuff. You can edit the query for that, but I'll break those out into config variables soon.

It'll show you a list of sales for a given day, with receipt numbers, each receipt broken down by patment if there are multiple payments, as well as per-day tax stuff.

Installation
------------

Copy `config.example.php` to `config.php` and edit for your mysql configuration. That's it!

License
-------

GPL3
