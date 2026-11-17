-- Base de datos inicial para Arena 7 (Cancha-Deportiva)
-- Ejecutar ANTES de: php bin/migrate.php
CREATE DATABASE IF NOT EXISTS cancha_deportiva CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE cancha_deportiva;

CREATE TABLE IF NOT EXISTS usuarios (
  id INT AUTO_INCREMENT PRIMARY KEY,
  correo VARCHAR(254) NOT NULL UNIQUE,
  password VARCHAR(255) NOT NULL,
  rol VARCHAR(30) NOT NULL DEFAULT 'administrador',
  creado TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS reservas (
  id INT AUTO_INCREMENT PRIMARY KEY,
  dni VARCHAR(15) NOT NULL,
  cliente VARCHAR(150) NOT NULL,
  telefono VARCHAR(20) NOT NULL,
  cancha VARCHAR(50) NOT NULL,
  fecha DATE NOT NULL,
  hora VARCHAR(20) NOT NULL,
  pago VARCHAR(30) NOT NULL,
  codigo_pago VARCHAR(100) NULL,
  monto DECIMAL(10,2) NOT NULL DEFAULT 0,
  detalle TEXT NULL,
  usuario VARCHAR(254) NULL,
  estado VARCHAR(20) NOT NULL DEFAULT 'Pendiente',
  creado TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS pagos (
  id INT AUTO_INCREMENT PRIMARY KEY,
  reserva_id INT NOT NULL,
  metodo VARCHAR(30) NOT NULL,
  codigo VARCHAR(100) NULL,
  monto DECIMAL(10,2) NOT NULL DEFAULT 0,
  estado VARCHAR(20) NOT NULL DEFAULT 'Pendiente',
  usuario VARCHAR(254) NULL,
  creado TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS auditoria (
  id INT AUTO_INCREMENT PRIMARY KEY,
  usuario VARCHAR(254) NULL,
  accion VARCHAR(50) NOT NULL,
  recurso VARCHAR(50) NULL,
  recurso_id INT NULL,
  detalle TEXT NULL,
  creado TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Cuenta administradora (debe coincidir con PRIVATE_DATA_OWNER).
-- La contraseña va en texto plano: bin/migrate.php la convierte en hash automáticamente.
INSERT IGNORE INTO usuarios (correo, password, rol)
VALUES ('admin@arena7.com', 'Arena7#2026', 'administrador');
