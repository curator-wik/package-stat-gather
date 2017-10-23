# 3rd-party dependency stats data miner for Drupal.org projects

 This project builds a database of all the Composer-declared dependencies from the most
 recent versions of all projects published on drupal.org, along with their project 
 associations and project usage statistics.

 The resulting data can be used to make inferences about which 3rd-party libraries are
 most important to the Drupal ecosystem.

## Installation
 1. clone the git repo
 2. Run `composer install`
 3. Run `sqlite3 database.sqlite < schema.sql` to generate the empty database.

## Usage
Run `curl https://drupal.org/files/releases.tsv | bin/gather-drupal.php`.

You'll get progress indicators while the data mining takes place. When complete,
you can use `sqlite3 database.sqlite` to analyze the raw data.
