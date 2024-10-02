CREATE DATABASE okx_data;
CREATE DATABASE binance_data;
CREATE DATABASE bybit_data;

\c okx_data
CREATE EXTENSION IF NOT EXISTS timescaledb;

\c binance_data
CREATE EXTENSION IF NOT EXISTS timescaledb;

\c bybit_data
CREATE EXTENSION IF NOT EXISTS timescaledb;