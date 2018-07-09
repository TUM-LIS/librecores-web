Tips and Tricks
===============

When developing, some commands and tools have proven helpful.
This page lists some (mostly unsorted) tips and tricks that make you more productive.

.. note::
  We use ``vm$>`` for commands to be executed inside the Vagrant-based development VM, and ``host$>`` for commands to be executed on the host PC as your normal user.

Connect to the VM
------------------
.. code-block:: bash

  # execute this inside your top-level code directory
  host$> cd vagrant
  host$> vagrant ssh

Rebuild ORM (Doctrine) Entity
-----------------------------

.. code-block:: bash

  vm$> cd /var/www/lc/site

  # for only one class
  vm$> ./bin/console doctrine:generate:entities LibrecoresProjectRepoBundle:Project

  # for all classes
  vm$> ./bin/console doctrine:generate:entities LibrecoresProjectRepoBundle

  # finally, update the MySQL DB
  vm$> ./bin/console doctrine:schema:update --force

Access the MySQL database
-------------------------
.. note::

  In the Vagrant development environment you can connect to the database with user "root" and password "password".

To access the database through a web frontend, phpMyAdmin is your friend. You find it at http://pma.librecores.devel.

If you prefer to access the MySQL database on the command line, you need to SSH into the VM.

.. code-block:: bash

   # use the mysql client to perform queries
   vm$> mysql -uroot -ppassword librecores

   # use mysqldump to get a dump of the whole database (or parts of it)
   mysqldump -uroot -ppassword librecores


(Yes, the password is "password".)


Asynchronous Processing with RabbitMQ
-------------------------------------

Access the RabbitMQ management plugin
~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~
http://librecores.devel:15672

- Username: admin
- Password: password

Run the consumer
~~~~~~~~~~~~~~~~

.. code-block:: bash

  vm$> cd /var/www/lc/site
  vm$> ./bin/console rabbitmq:consumer -m 1 update_project_info

Test the producer: update one project
~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

.. code-block:: bash

  vm$> cd /var/www/lc/site
  # update the project information of project 1 (needs the consumer!)
  vm$> echo 1 | ./bin/console rabbitmq:stdin-producer update_project_info

Empty the queue
~~~~~~~~~~~~~~~

.. code-block:: bash

  vm$> sudo rabbitmqctl purge_queue update-project-info


Clean the Symfony caches
------------------------
.. code-block:: bash

  vm$> cd /var/www/lc/site
  vm$> ./bin/console cache:clear

Remote PHP debugging
--------------------

The development environment has Xdebug remote debugging enabled using the common default settings:
``xdebug.remote_port`` is set to port 9000 and `xdebug.remote_connect_back` is set to ``1``.
Please refer to your IDEs manual for further information how to make use of this functionality.

Check the coding style of PHP code
----------------------------------

.. code-block:: bash

  vm$> cd /var/www/lc/site
  vm$> ./vendor/bin/phpcs --runtime-set ignore_warnings_on_exit true -s \
    && echo You can commit: No errors found!

Algolia indices configuration
-----------------------------
To configure Algolia in the development environment you need to specify the Application ID (site_algolia_app_id) and
the Admin API Key (site_algolia_api_key) in the ansible/secrets/dev-vagrant.secrets.yml file.
you have to clear and import the search indices settings for pushing data to algolia.

Clear indices
-------------
.. code-block:: bash

  vm$> cd /var/www/lc/site
  vm$> ./bin/console search:clear

Import all indices
------------------
.. code-block:: bash

  vm$> cd /var/www/lc/site
  vm$> ./bin/console search:import