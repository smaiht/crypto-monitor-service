# Cryptocurrency Data Monitoring Service (PHP, CCXT, TimescaleDB, Redis)

This is part of a real-time cryptocurrency data monitoring system. It collects data from a specified cryptocurrency exchange, processes it, sends it to the hub, and stores it in a TimescaleDB database.

## IMPORTANT NOTICE

**This is only a part of the system and will not work without the installed and running repository: [https://github.com/smaiht/crypto-monitor-hub](https://github.com/smaiht/crypto-monitor-hub)**

**Please install and set up the crypto-monitor-hub first before proceeding with the installation here.**


## System Architecture Overview

1. Scalable Design:
   Each exchange can be monitored on a separate machine with its own database. The main hub can query all machines for specific data.

2. Efficient Data Storage:
   Using TimescaleDB with materialized views for each exchange:
   - `ticker_data`: Contains records for all pairs for each second. Data is collected over a configurable interval (configurable to 5 seconds via .env) before being inserted. Kept for 24 hours.
   - Materialized views use continuous aggregators to populate corresponding tables:
     - `ticker_data_1m`: 1-minute aggregated data, stored for up to 7 days.
     - `ticker_data_1h`: 1-hour aggregated data, stored indefinitely.

3. Performance Optimization:
   - Composite indexes on trading pairs and time.
   - Table partitioning by trading pairs and time.

Note: Estimated database size for 1 exchange after 1 year: ~1 GB.


## Installation

### Using Docker

1. Create a shared network (if not already):
~~~
docker network create shared-services-network
~~~

2. Clone this repository:
~~~
git clone https://github.com/smaiht/crypto-monitor-service.git
cd crypto-monitor-service
~~~

3. Start the TimescaleDB service:
~~~
docker-compose -f docker-timescaledb-compose.yml up --build
~~~
Note: This service uses the official TimescaleDB docker image. To use a local TimescaleDB instance, edit the `docker.env` files in both the -service and -hub repo folders.

4. Configure the exchange for this service:
Edit the `docker.env` file and set the `EXCHANGE` variable to the desired exchange (e.g., 'binance', 'okx', 'bybit')

5. Start the service (monitor and aggregator will run in separate containers):
~~~
docker-compose up --build
~~~



### Local Installation

1. Install necessary system packages and PHP extensions:
~~~
sudo apt-get update && sudo apt-get install -y 
libpq-dev 
libzip-dev 
libicu-dev 
libgmp-dev 
libssl-dev 
libevent-dev 
libev-dev 
php-pdo 
php-pgsql 
php-zip 
php-bcmath 
php-intl 
php-gmp 
php-pcntl 
php-sockets
sudo pecl install redis
sudo phpenmod redis
~~~

2. Install project dependencies:
~~~
composer install
~~~
3. Configure the `.env` file with appropriate settings.

4. Start the monitor and aggregator in separate terminals:
~~~
php bin/monitor.php start
~~~
~~~
php bin/aggregator.php start
~~~



## Scaling

To monitor multiple exchanges simultaneously:

1. Create a copy of the project folder for each additional exchange.
2. In each copy, edit the `docker.env` file to set the appropriate `EXCHANGE` value (e.g., 'binance', 'okx', 'bybit').
3. Start each service from its respective folder.



## Database Access

TimescaleDB instance can be accessed with the following credentials:
- Host: `localhost` (`timescaledb` inside docker for shared networks)
- Port: `5433`
- Database names: `okx_data`, `binance_data`, `bybit_data`
- User: postgres
- Password: 12121212


Note: by default docker initiats 3 databases inside timescaledb: `okx_data`, `binance_data`, `bybit_data`. If you want using different exchanges you should edit file `init-databases.sql` accordingly.