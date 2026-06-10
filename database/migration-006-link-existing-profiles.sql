UPDATE partners AS partner
SET user_id = account.id
FROM users AS account
WHERE partner.user_id IS NULL
  AND LOWER(TRIM(partner.email)) = LOWER(TRIM(account.email))
  AND NOT EXISTS (
    SELECT 1
    FROM partners AS existing
    WHERE existing.user_id = account.id
  );
