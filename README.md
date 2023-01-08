# Debian dashboard

## How to update the data

```sh
# it needs php8.1 php8.1-pdo-pgsql
php -f fetch-data.php
```

## Where is the data stored ?

In [debian-dashboard/debian.dashboard.air-balloon.cloud/data/udd.json](debian-dashboard/debian.dashboard.air-balloon.cloud/data/udd.json)

## Where is the data from

It is mainly from Debian's [UDD database](https://udd-mirror.debian.net/)
