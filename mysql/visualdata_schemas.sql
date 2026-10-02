--
-- Table structure
--

CREATE TABLE IF NOT EXISTS /*_*/visualdata_schemas (
	`id` int(11) NOT NULL,
	`name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
	`updated_at` datetime NOT NULL,
	`created_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

--
-- Indexes
--
ALTER TABLE /*_*/visualdata_schemas
	ADD PRIMARY KEY (`id`);

--
-- AUTO_INCREMENT
--
ALTER TABLE /*_*/visualdata_schemas
	MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

