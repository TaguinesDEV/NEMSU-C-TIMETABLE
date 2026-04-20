#!/usr/bin/env python3
import mysql.connector

db_config = {
    'host': 'localhost',
    'user': 'root', 
    'password': '',
    'database': 'academic_scheduling'
}

conn = mysql.connector.connect(**db_config)
cursor = conn.cursor(dictionary=True)

cursor.execute('''
    SELECT id, status, progress_percent, current_generation, best_fitness, 
           created_at, error_message 
    FROM schedule_jobs 
    ORDER BY id DESC 
    LIMIT 5
''')

rows = cursor.fetchall()

print('\n' + '='*80)
print('LAST 5 JOBS STATUS')
print('='*80)

for row in rows:
    job_id = row['id']
    status = row['status']
    progress = row['progress_percent']
    gen = row['current_generation']
    fitness = row['best_fitness']
    created = row['created_at']
    error = row['error_message']
    
    print(f'\nJob {job_id}:')
    print(f'  Status: {status}')
    print(f'  Progress: {progress}%')
    print(f'  Generation: {gen}')
    print(f'  Fitness: {fitness}%')
    print(f'  Created: {created}')
    if error:
        print(f'  ❌ Error: {error}')

cursor.close()
conn.close()
