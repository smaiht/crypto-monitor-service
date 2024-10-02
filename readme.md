# Cryptocurrency Data Monitoring Service
- PHP, CCXT, TimescaleDB (pgsql), Redis (pub/sub)

This is part of a real-time cryptocurrency data monitoring system. It collects data from a specified cryptocurrency exchange, processes it, sends it to the hub, and stores it in a TimescaleDB database.


## IMPORTANT NOTICE

**This is only a part of the system and will not work without the installed and running repository: [https://github.com/smaiht/crypto-monitor-hub](https://github.com/smaiht/crypto-monitor-hub)**

**Please install and set up the crypto-monitor-hub first before proceeding with the installation here.**


## System Architecture Overview

1. Central Hub:
   - Laravel application with Filament admin panel.
   - Receives data from all monitoring services via Redis pub/sub.
   - Provides real-time monitoring of trading pairs for all exchanges (or a specific one) via WebSockets in the browser.
   - API for querying prices by pair/exchange/time (can query all machines for specific data)

2. Monitoring Services (one per exchange):
   - Can be deployed on separate servers with their own databases.
   - Consists of two components:
     a) Monitor: Uses CCXT to subscribe to exchange tickers and publish data to Redis.
     b) Aggregator: Listens to the corresponding Redis channel, analyzes, and writes data to the database.

3. Data Storage in TimescaleDB for optimized time-series data handling:
   a) Main table `ticker_data`:
      - Contains records for all pairs with second precision.
      - Data insertion occurs in batches with a configurable interval (default 5 seconds).
      - Data is kept for 24 hours.
   b) Materialized views with continuous aggregators:
      - `ticker_data_1m`: 1-minute aggregation, stored for 7 days.
      - `ticker_data_1h`: 1-hour aggregation, stored indefinitely.
   c) Composite indexes on trading pairs and time.
   d) Table partitioning by trading pairs and time.

4. Containerization and Scalability:
   - The system is fully dockerized, ensuring quick deployment and scaling.
   - Easy addition of new monitoring services for additional exchanges.
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