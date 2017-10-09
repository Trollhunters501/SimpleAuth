CREATE TABLE IF NOT EXISTS simpleauth_players (
  name VARCHAR(16) PRIMARY KEY,
  hash CHAR(128),
  registerdate INT,
  logindate INT,
  lastip VARCHAR(50),
  ip VARCHAR(50),
  linkedign VARCHAR(16)
);