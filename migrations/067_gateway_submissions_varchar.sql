-- Stop ENUM drift when new partners are added to getPartnerRegistry().
ALTER TABLE gateway_submissions
    MODIFY gateway VARCHAR(40) NOT NULL;
