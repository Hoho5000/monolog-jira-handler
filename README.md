# JIRA Handler for Monolog

[![Build Status](https://travis-ci.com/Hoho5000/monolog-jira-handler.svg?branch=master)](https://travis-ci.com/Hoho5000/monolog-jira-handler)

## Introduction
This handler will write the logs to a JIRA instance. The handler will calculate a hash over the log-data except 
time sensitive data. It then will query the JIRA REST API to determe if there is already a JIRA Issue with the
corresponding hash. If so, the handler will do nothing. If there is no issue matching the hash the handler will 
create a new issue with the content of the log entry.

## Installation
You can install it through [Composer](https://getcomposer.org):

```shell
$ composer require hoho5000/monolog-jira-handler
```

## Usage
With this setup each log entry is transmitted to JIRA. 
```php
<?php

$jiraHandler = new JiraHandler('your.jira.host', 'username', 'password', 'project = MYAPP AND resolution = Unresolved', 'Loghash', 'MYAPP', 'Bug', true, 'Logcount');

$loger = new Logger('name');
$loger->pushHandler($jiraHandler);

$loger->error('something went wrong...');
```

If there are several log entries, it makes more sense to buffer the log entries first and transfer them all to JIRA in one step.
```php
<?php

$jiraHandler = new JiraHandler('your.jira.host', 'username', 'password', 'project = MYAPP AND resolution = Unresolved', 'Loghash', 'MYAPP', 'Bug', true, 'Logcount');
$bufferHandler = new \Monolog\Handler\BufferHandler($jiraHandler);
$fingersCrossedHandler = new \Monolog\Handler\FingersCrossedHandler($bufferHandler);

$loger = new Logger('name');
$loger->pushHandler($fingersCrossedHandler);

// log records
$loger->debug('d1');
$loger->debug('d2');
$loger->debug('d3');
$loger->info('I am somehow nice to know');
$loger->error('something went wrong...');
```


## Handler configuration
The JiraHandler has several constructor arguments:
- **`$hostname`**: The hostname of your JIRA instance without the protocol (https:// is enforced).
- **`$username`**: The username under which the issues are created (and any other operation on the JIRA REST API is performed).
- **`$password`**: The password to authenticate against your JIRA instance
- **`$jql`**: The JQL which is used to check if there is already an existing issue regarding the currently processed log entry. This allows to log the very same error on different php projects which will create an issue in a different JIRA project.
- **`$hashFieldName`**: The name of the custom field which will store the md5 hash of the log entry
- **`$projectKey`**: The project key under which a new issue will be created
- **`$issueTypeName`**: The issue type which the new issue will use while being created
- **`$withComments`**: (default: `false`) Determines if subsequent same (same hash) log entries will be added as comments to the already created issue
- **`$counterFieldName`**: (default: `null`) If set this is the name of the custom field containing the number of recorded log entries
- **`$httpClient`**: (default: `null`) The HTTP Client which is used to talk to the JIRA REST API. Any [HTTPlug client implementation](https://packagist.org/providers/php-http/client-implementation) is allowed. If not specified it will try to autodiscover a suitable client.

## License
This library is licensed under the MIT License - see the LICENSE file for details
