-- {{APP_NAME}} Demo Database Schema

-- Create demo_items table
CREATE TABLE IF NOT EXISTS demo_items (
    id SERIAL PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT NULL
);

-- Insert sample data
INSERT INTO demo_items (name, description) VALUES 
    ('Welcome Item', 'This is your first demo item created automatically'),
    ('Second Item', 'Another example item to show the list functionality'),
    ('API Test', 'This item demonstrates the API endpoints');

-- Create index for performance
CREATE INDEX IF NOT EXISTS idx_demo_items_created_at ON demo_items(created_at);