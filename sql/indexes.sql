USE isuconp;

ALTER TABLE comments
  ADD INDEX idx_comments_post_created (post_id, created_at DESC);

-- ALTER TABLE posts
--   ADD INDEX idx_posts_created (created_at DESC);

-- ALTER TABLE posts
--   ADD INDEX idx_posts_user (user_id);
