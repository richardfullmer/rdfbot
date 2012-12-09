RDFBot - In house travis for your private repos
===============================================

Install:

    $ curl http://getcomposer.org/installer | php
    $ composer.phar install
    $ cp app/config/config.yml.dist app/config/config.yml

Run with cron

    $ php app/console check -u username -p password
