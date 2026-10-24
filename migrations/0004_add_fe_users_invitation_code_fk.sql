-- Mit Code registrierte Benutzer gehören zur Stadt des Codes. Erst hier, weil fe_invitation_codes
-- ihrerseits auf fe_users verweist.
ALTER TABLE fe_users ADD CONSTRAINT fk_fe_users_invitation_code
    FOREIGN KEY (invitation_code_id, tenant_id) REFERENCES fe_invitation_codes (id, tenant_id);
