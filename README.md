Start of by creating the table:

```sql
CREATE TABLE `attendees`(
	`id` INT NOT NULL AUTO_INCREMENT,
	`code` VARCHAR(255) NOT NULL,
	`source` ENUM('eventioz', 'evenbrite') NOT NULL,
	`email` VARCHAR(255) NOT NULL,
	`first_name` VARCHAR(255) default NULL,
	`last_name` VARCHAR(255) default NULL,
	`checkin_day1` DATETIME default NULL,
	`checkin_day2` DATETIME default NULL,
	PRIMARY KEY(`id`),
	UNIQUE KEY `attendees__code__source`(`source`, `code`)
);
```

Then create the cache directory:

```bash
$ mkdir cache
$ chmod a+w cache
```

Finally create the configuration files `src/config.ini`:

```
[production]
db.driver    = pdo_mysql
db.dbname    = phpconfar
db.host      = localhost
db.user      = root
db.password  = password
urls.evenbrite = "https://www.eventbrite.com/json/event_list_attendees?app_key=APP_KEY&user_key=USER_KEY"
urls.eventioz = "https://eventioz.com.ar/admin/events/php-conference-argentina/registrations.json?api_key=API_KEY"
users.mariano = my_password
users.claudia = another_password
```
