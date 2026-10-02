--
-- Table structure
--

CREATE TABLE IF NOT EXISTS /*_*/visualdata_links_template (
  `id` int(11) NOT NULL,
  `parent_page_id` int(11) NOT NULL,
  `page_id` int(11) NOT NULL,
  `schema_id` int(11) NOT NULL,
  `updated_at` datetime NOT NULL,
  `created_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

--
-- Indexes
--
ALTER TABLE /*_*/visualdata_links_template
  ADD PRIMARY KEY (`id`);

ALTER TABLE /*_*/visualdata_links_template
	ADD INDEX `parent_page_id` (`parent_page_id`);

ALTER TABLE /*_*/visualdata_links_template
	ADD INDEX `page_id` (`page_id`);

--
-- AUTO_INCREMENT
--
ALTER TABLE /*_*/visualdata_links_template
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

